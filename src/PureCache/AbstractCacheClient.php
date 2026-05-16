<?php

declare(strict_types=1);

namespace PureCache;

use PureCache\Internal\AbstractClientPeclArithTrait;
use PureCache\Internal\AbstractClientPeclDeleteTrait;
use PureCache\Internal\AbstractClientPeclPoolTrait;
use PureCache\Internal\AbstractClientPeclReadTrait;
use PureCache\Internal\AbstractClientPeclStoreTrait;
use PureCache\Internal\CacheEntry;
use PureCache\Internal\ClientCoordinatorBinding;
use PureCache\Internal\ClientCoordinatorRegistry;
use PureCache\Internal\ClientCoreState;
use PureCache\Internal\ClientOptionResult;
use PureCache\Internal\EncodingContext;
use PureCache\Internal\KeyFormatter;
use PureCache\Internal\OptionEnvironment;
use PureCache\Internal\StoreMode;
use PureCache\Internal\ValueCodec;
use PureCache\Memcached\Internal\TimeoutException;

/**
 * Backend-agnostic PECL {@code \Memcached}-shaped surface.
 *
 * This base class owns everything that does not touch the wire protocol:
 *  - server list manipulation (add/get/reset/setBucket)
 *  - PECL option storage and dispatch (via {@see ClientOptionApplier})
 *  - result code/message accounting and {@see getLastErrorErrno()} bookkeeping
 *  - delayed-fetch queue (`getDelayed` / `fetch` / `fetchAll`)
 *  - shared key validation, prefixing, routing and {@code SOCKET}/{@code TCP} typing
 *  - PECL-style key coercion helpers used by `*Multi*` methods
 *
 * Concrete subclasses ({@see Memcached\MemcachedClient}, {@see Redis\RedisClient}) implement
 * the per-protocol primitives ({@see doGet()}, {@see doStore()}, {@see doDelete()}, …) and
 * the {@see OptionEnvironment} hooks that let option changes invalidate backend resources.
 *
 * @template TState of ClientCoreState
 *
 * @psalm-suppress MixedArgumentTypeCoercion
 */
abstract class AbstractCacheClient extends MemcachedConstants implements CacheClient, OptionEnvironment
{
    use AbstractClientPeclArithTrait;
    use AbstractClientPeclDeleteTrait;
    use AbstractClientPeclPoolTrait;
    use AbstractClientPeclReadTrait;
    use AbstractClientPeclStoreTrait;

    /** @var TState */
    protected ClientCoreState $core;

    private ?ClientCoordinatorRegistry $coordinatorRegistry = null;

    protected bool $pristine = true;

    /** @var non-empty-string|null */
    protected ?string $poolKey = null;

    public function __construct(
        protected readonly ?string $persistentId = null,
        ?callable $callback = null,
        ?string $connection_str = null,
    ) {
        $pid = (null !== $persistentId && '' !== $persistentId) ? $persistentId : null;

        $reused = null !== $pid ? $this->lookupPersistentState($pid) : null;
        if ($reused instanceof ClientCoreState) {
            /** @var TState $reusedCore */
            $reusedCore = $reused;
            $this->core = $reusedCore;
            $this->poolKey = $pid;
            $this->pristine = false;

            return;
        }

        $this->core = $this->createState($pid);

        if (null !== $connection_str && '' !== $connection_str) {
            foreach (Internal\ConnectionStringParser::parseServers($connection_str) as $s) {
                if (0 === $s['port']) {
                    $s['port'] = $this->defaultPort();
                }

                $this->core->selector->addServer($s);
            }

            $this->onPoolInvalidated();
        }

        if (null !== $callback) {
            try {
                $callback($this, $pid);
            } catch (\Throwable $e) {
                $this->onPoolInvalidated();
                throw $e;
            }
        }

        $this->pristine = true;

        if (null !== $pid) {
            $this->registerPersistentState($pid, $this->state());
            $this->poolKey = $pid;
        }
    }

    public function __destruct()
    {
        if (null !== $this->poolKey) {
            return;
        }

        try {
            $this->onPoolInvalidated();
        } catch (\Throwable) {
        }
    }

    /**
     * @return TState
     */
    final protected function state(): ClientCoreState
    {
        return $this->core;
    }

    // -----------------------------------------------------------------------
    // Subclass extension points
    // -----------------------------------------------------------------------

    /**
     * @return TState
     */
    abstract protected function createState(?string $persistentId): ClientCoreState;

    abstract protected function lookupPersistentState(string $persistentId): ?ClientCoreState;

    abstract protected function registerPersistentState(string $persistentId, ClientCoreState $state): void;

    /** Default port used by {@see addServer()} / {@see addServers()}. */
    abstract protected function defaultPort(): int;

    /** Flush any backend write buffers (called before remote reads). */
    abstract protected function flushNetworkWrites(): void;

    abstract protected function doGet(string $key, string $prefixedKey, ?string $serverKey, int $getFlags): mixed;

    /**
     * @param list<string> $keys
     *
     * @return array<string, mixed>|false found items keyed by original key (subset of $keys)
     */
    abstract protected function doGetMulti(array $keys, ?string $serverKey, int $getFlags): array|false;

    /**
     * Read a single delayed batch synchronously and produce ready-to-fetch
     * {@code ['key' => …, 'value' => …, 'cas' => …, 'flags' => …]} rows.
     *
     * @param list<string> $keys
     *
     * @return list<array<string, mixed>>|false {@code false} signals a fatal I/O error (result code already set);
     *                                          an empty list means "all keys missed" (still success)
     */
    abstract protected function doFetchBatch(array $keys, ?string $serverKey, bool $withCas): array|false;

    /**
     * @param list<string>                                     $keys
     * @param callable(CacheClient, array<string, mixed>):void $valueCb
     */
    abstract protected function doGetDelayedValueCallback(array $keys, ?string $serverKey, bool $withCas, callable $valueCb): bool;

    /**
     * @return array<string, mixed>|false
     */
    abstract protected function doGetStats(?string $type): array|false;

    /**
     * @return array<string, string>|false
     */
    abstract protected function doGetVersion(): array|false;

    abstract protected function doFlush(int $delay): bool;

    /**
     * @return list<string>|false
     */
    abstract protected function doGetAllKeys(): array|false;

    #[\Override]
    protected function setResult(int $code, ?string $message = null): void
    {
        $this->core->resultCode = $code;
        $this->core->resultMessage = $message ?? Internal\ClientResultCatalog::defaultMessage($code);
        if (self::RES_E2BIG === $code) {
            Internal\ClientObserverNotifier::notifyItemTooBig($this->core, null, 0);
        }
    }

    /**
     * Encoding context currently in force, or {@code null} when
     * {@link CacheClient::setEncodingKey()} has not been called (or was last
     * called with an unsupported mode). Backends pipe this value into
     * {@see ValueCodec::encode()} /
     * {@see ValueCodec::decode()} so encryption stays a
     * pure value-pipeline concern and never leaks into protocol handling.
     */
    protected function encodingContext(): ?EncodingContext
    {
        return $this->core->encoding;
    }

    // -----------------------------------------------------------------------
    // OptionEnvironment defaults (concrete clients override what they need)
    // -----------------------------------------------------------------------

    #[\Override]
    public function onTimeoutsChanged(): void
    {
        $this->onPoolInvalidated();
    }

    #[\Override]
    public function unsupportedOptionMessage(): string
    {
        return 'option is not supported by this cache backend';
    }

    #[\Override]
    public function applyCustomOption(int $option, mixed $value, ClientCoreState $core): ?ClientOptionResult
    {
        return Internal\ClientCustomOptionHandler::apply($option, $value, $core, $this);
    }

    // -----------------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------------

    #[\Override]
    protected function prefixedKey(string $key): string
    {
        return KeyFormatter::prefixed($key, $this->core->options);
    }

    protected function routingKey(string $itemKey): string
    {
        return KeyFormatter::routing($itemKey, $this->core->options);
    }

    protected function checkKeyInternal(string $key): bool
    {
        return KeyFormatter::isValid(
            $key,
            $this->core->optionBool(self::OPT_VERIFY_KEY, true),
            $this->maxKeyLength(),
        );
    }

    /**
     * Hard byte-length limit applied by {@see checkKeyInternal()}. Defaults to
     * memcached's {@code KEY_MAX_LENGTH = 250} so the meta protocol stays
     * happy; non-memcached backends (Redis, Ignite, …) override this to widen
     * the limit to whatever their wire format actually supports.
     *
     * Exposed via {@see OptionEnvironment::maxKeyLength()} so the shared
     * {@see ClientOptionApplier} can validate {@code OPT_PREFIX_KEY} against
     * each backend's actual limit instead of memcached's 250-byte ceiling.
     */
    #[\Override]
    public function maxKeyLength(): int
    {
        return 250;
    }

    protected function optionInt(int $option, int $default): int
    {
        return $this->core->optionInt($option, $default);
    }

    protected function optionBool(int $option, bool $default): bool
    {
        return $this->core->optionBool($option, $default);
    }

    protected function useNoReply(): bool
    {
        return $this->core->optionBool(self::OPT_NOREPLY, false);
    }

    protected function shouldBufferNoReplyWrite(): bool
    {
        return $this->useNoReply() && $this->core->optionBool(self::OPT_BUFFER_WRITES, false);
    }

    protected function ensureServersAvailable(): bool
    {
        return $this->coordinators()->routing()->ensureServersAvailable();
    }

    /**
     * After a read path returns no entry, map the outcome to {@code RES_NOTFOUND}
     * unless a more specific code is already set ({@code RES_PAYLOAD_FAILURE},
     * {@code RES_E2BIG}, …).
     */
    protected function applyMissUnlessReadFailure(): void
    {
        $code = $this->getResultCode();
        if (self::RES_PAYLOAD_FAILURE !== $code && self::RES_E2BIG !== $code) {
            $this->setResult(self::RES_NOTFOUND);
        }
    }

    /**
     * @param array<mixed> $items
     */
    #[\Override]
    protected function storeMultiValidate(?string $serverKey, array $items): bool
    {
        return $this->coordinators()->storeMulti()->validate($serverKey, $items);
    }

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, bool|int>
     */
    #[\Override]
    protected function runDeleteMulti(array $keys, ?string $serverKey, int $time): array
    {
        return $this->deleteMultiCommon($keys, $serverKey, $time);
    }

    #[\Override]
    protected function coordinators(): ClientCoordinatorRegistry
    {
        return $this->coordinatorRegistry ??= new ClientCoordinatorRegistry($this->createCoordinatorBinding());
    }

    protected function createCoordinatorBinding(): ClientCoordinatorBinding
    {
        return new ClientCoordinatorBinding(
            $this->core,
            $this,
            $this,
            $this->setResult(...),
            fn (): int => $this->getResultCode(),
            fn (int $option, int $default): int => $this->optionInt($option, $default),
            fn (int $option, bool $default): bool => $this->optionBool($option, $default),
            fn (string $key): string => $this->prefixedKey($key),
            fn (string $key): string => $this->routingKey($key),
            fn (string $key): bool => $this->checkKeyInternal($key),
            function (): void {
                $this->onPoolInvalidated();
            },
            function (): void {
                $this->flushNetworkWrites();
            },
            fn (): int => $this->defaultPort(),
            fn (): ?EncodingContext => $this->encodingContext(),
            fn (mixed $key): string => $this->keyToString($key),
            fn (array $keys): array => $this->keyStrings($keys),
            fn (): bool => $this->ensureServersAvailable(),
            fn (string $key, string $prefixedKey, ?string $serverKey, int $getFlags): mixed => $this->doGet($key, $prefixedKey, $serverKey, $getFlags),
            fn (array $keyStrings, ?string $serverKey, int $getFlags): array|false => $this->doGetMulti($keyStrings, $serverKey, $getFlags),
            fn (string $key, ?string $serverKey, int $time): bool => $this->doDelete($key, $serverKey, $time),
            fn (array $keys, ?string $serverKey, bool $withCas, callable $valueCb): bool => $this->doGetDelayedValueCallback(
                $keys,
                $serverKey,
                $withCas,
                /*
                 * @param callable(CacheClient, array<string, mixed>): void $valueCb
                 */
                $valueCb,
            ),
            fn (array $keys, ?string $serverKey, bool $withCas): array|false => $this->doFetchBatch($keys, $serverKey, $withCas),
            fn (string $key, mixed $value, int $expiration): bool => $this->set($key, $value, $expiration),
            fn (string $key, int $getFlags): mixed => $this->get($key, null, $getFlags),
            fn (string $serverKey, string $key, mixed $value, int $expiration): bool => $this->setByKey($serverKey, $key, $value, $expiration),
            fn (string $serverKey, string $key, int $getFlags): mixed => $this->getByKey($serverKey, $key, null, $getFlags),
        );
    }

    /**
     * Fan-out helper for whole-pool operations ({@code getStats()}, {@code getVersion()},
     * {@code flush()}, {@code getAllKeys()}). Walks every configured server, keys
     * the per-shard result by {@code "host:port"} and records hard transport
     * failures via {@see recordServerFailure()}.
     *
     * Closure contract:
     *  - return {@code TValue} on success — stored as-is at {@code "host:port"};
     *  - return {@code null} on a "soft" failure (protocol-level miss that does
     *    *not* implicate the server) — slot gets {@code $failureValue},
     *    {@code allOk} flips to {@code false}, the failure tracker is *not*
     *    touched;
     *  - throw {@code \Throwable} on a "hard" failure — slot gets
     *    {@code $failureValue}, {@code allOk} flips to {@code false}, and
     *    {@see recordServerFailure()} is invoked so the libmemcached-shaped
     *    failure machinery sees the error.
     *
     * @template TValue
     *
     * @param \Closure(int $serverIndex, array{host:string,port:int,weight:int,user?:string,password?:string,database?:int,tls?:bool,tls_ca_file?:string}): (TValue|null) $task
     * @param TValue                                                                                                                                                      $failureValue value stored in the slot for both soft (null) and hard (throw) failures
     *
     * @return array{values: array<string, TValue>, allOk: bool, anyOk: bool}
     */
    protected function collectFromServers(\Closure $task, mixed $failureValue): array
    {
        return $this->coordinators()->pool()->collectFromServers($task, $failureValue);
    }

    /**
     * Pick the routing index for {@code $key}, honouring an optional server-key override.
     */
    protected function pickServerIndex(?string $serverKey, string $key): int
    {
        return $this->coordinators()->routing()->pickServerIndex($serverKey, $key);
    }

    /**
     * Like {@see pickServerIndex()} but consults
     * {@code OPT_NUMBER_OF_REPLICAS} + {@code OPT_RANDOMIZE_REPLICA_READ} to
     * spread read traffic across the configured replica set when both are
     * enabled. Falls back to the primary when replicas are disabled or the
     * randomized pick would otherwise return the same index.
     */
    protected function pickReadServerIndex(?string $serverKey, string $key): int
    {
        return $this->coordinators()->routing()->pickReadServerIndex($serverKey, $key);
    }

    /**
     * Wrap {@see writeFanout()} with {@code OPT_STORE_RETRY_COUNT} retries onto
     * a different live server when the primary fan-out comes back with
     * {@code RES_FAILURE}. Non-failure outcomes (RES_NOTSTORED, RES_DATA_EXISTS,
     * RES_E2BIG…) are surfaced verbatim so {@code add()/replace()/cas()} keep
     * their PECL contract.
     */
    protected function retryStoreOnFailure(?string $serverKey, string $key, \Closure $writer): bool
    {
        return $this->coordinators()->write()->retryStoreOnFailure($serverKey, $key, $writer);
    }

    /**
     * Resolve the {@code [primary, replicas]} index pair for a single key
     * under {@code OPT_NUMBER_OF_REPLICAS}. Returned in the shape that
     * pipelined multi-writers want: a primary slot plus a list of extra
     * replica slots, suitable for tagging each per-server batch entry as
     * authoritative or best-effort. Returns {@code null} when the live
     * server pool can't accommodate any write at all (mirrors what
     * {@see writeFanout()} would report as {@code RES_NO_SERVERS}).
     *
     * @return array{primary: int, replicas: list<int>}|null
     */
    protected function fanoutTargets(?string $serverKey, string $key): ?array
    {
        return $this->coordinators()->routing()->fanoutTargets($serverKey, $key);
    }

    /**
     * Fan-out a single write to the primary plus any {@code OPT_NUMBER_OF_REPLICAS}
     * additional servers. Replica failures are best-effort and never propagated
     * — the caller's outcome is whatever the primary returned, with its
     * {@code RES_*} preserved so the public surface mirrors a single-server write.
     *
     * @param \Closure(int $serverIndex): bool $writer must return {@code true} when the write succeeded on {@code $serverIndex}; if it throws, the exception is captured as a per-server failure
     */
    protected function writeFanout(?string $serverKey, string $key, \Closure $writer): bool
    {
        return $this->coordinators()->write()->writeFanout($serverKey, $key, $writer);
    }

    /**
     * Group {@code $keys} (already coerced to strings) by their target shard, returning
     * pairs of {@code [originalKey, prefixedKey]}. {@code $serverKey} short-circuits the
     * Ketama/modula lookup so every key in the call goes to the same shard.
     *
     * @param list<string> $keys
     *
     * @return array<int, list<array{0:string,1:string}>>
     */
    protected function groupKeysByServer(array $keys, ?string $serverKey): array
    {
        return $this->coordinators()->routing()->groupKeysByServer($keys, $serverKey);
    }

    /**
     * Boilerplate-free pre-flight for an operation routed by a single PECL key.
     *
     * Validates the prefixed key + the (optional) server-key, ensures the server
     * pool is non-empty, and picks the routing index. On any pre-flight failure
     * the appropriate {@code RES_*} is set and the closure is not invoked; on
     * a thrown protocol-level exception {@see recordServerFailure()} is invoked
     * and the result is set to {@code RES_FAILURE}. In both failure cases the
     * supplied {@code $failureValue} is returned so the caller can use the same
     * sentinel as the PECL surface.
     *
     * @template TResult
     *
     * @param \Closure(int $serverIndex, string $prefixedKey): TResult $body
     * @param TResult                                                  $failureValue value returned on any pre-flight failure or thrown exception
     *
     * @return TResult
     */
    protected function executeKeyed(
        string $key,
        ?string $serverKey,
        \Closure $body,
        mixed $failureValue = false,
        bool $forRead = false,
        bool $fanoutWrite = false,
    ): mixed {
        return $this->coordinators()->keyed()->executeKeyed($key, $serverKey, $body, $failureValue, $forRead, $fanoutWrite);
    }

    /**
     * Update the failure tracker plus {@code lastDisconnectedServer} when an
     * operation against {@code $serverIndex} fails. {@see TimeoutException}
     * (and PECL-compatible {@code 'timeout'}/{@code 'timed out'} substrings
     * in the message) is reported separately so libmemcached's
     * {@code OPT_SERVER_TIMEOUT_LIMIT} accounting works alongside
     * {@code OPT_SERVER_FAILURE_LIMIT}.
     */
    protected function recordServerFailure(?int $serverIndex, \Throwable $throwable): void
    {
        $this->coordinators()->health()->recordServerFailure($serverIndex, $throwable);
    }

    /**
     * Reset the failure tracker for {@code $serverIndex} after a successful
     * round-trip. Mirrors libmemcached's "clear-on-success" contract so a
     * single recovery hides previous failures.
     */
    protected function recordServerSuccess(?int $serverIndex): void
    {
        $this->coordinators()->health()->recordServerSuccess($serverIndex);
    }

    /**
     * @return string the key in canonical string form, or {@code ''} (which
     *                {@see checkKeyInternal()} will subsequently reject as
     *                {@code RES_BAD_KEY_PROVIDED}) if {@code $key} is not a
     *                memcached-compatible scalar or {@see \Stringable}
     */
    protected function keyToString(mixed $key): string
    {
        return $this->coordinators()->keyHelper()->toString($key);
    }

    /**
     * @param array<mixed> $keys
     *
     * @return list<string>
     */
    protected function keyStrings(array $keys): array
    {
        return $this->coordinators()->keyHelper()->strings($keys);
    }

    protected function casValue(?string $cas): int|string
    {
        return Internal\ClientKeyHelper::casValue($cas);
    }

    protected function valueForGetFlags(CacheEntry $entry, int $getFlags): mixed
    {
        return Internal\ClientKeyHelper::valueForGetFlags($entry, $getFlags);
    }

    /**
     * @return array<string, mixed>
     */
    protected function delayedEntry(string $key, CacheEntry $entry, bool $withCas): array
    {
        return Internal\ClientKeyHelper::delayedEntry($key, $entry, $withCas);
    }

    /**
     * Pre-flight shared by every backend's {@code doDelete()} implementation.
     *
     * Centralises the validation order that all three backends previously
     * unrolled manually: PECL parity requires {@code bad key > delete-time
     * > no servers > protocol outcome}, and {@see executeKeyed()} would
     * short-circuit on the empty pool *before* {@see acceptDeleteTime()} —
     * which is why the original implementations open-coded the same
     * sequence.
     *
     * The body receives the prefixed key and is expected to handle its own
     * server selection / replica fan-out (delete is always {@see writeFanout()}-d).
     *
     * @param \Closure(string $prefixedKey): bool $body
     */
    protected function executeDelete(string $key, ?string $serverKey, int $time, \Closure $body): bool
    {
        return $this->coordinators()->delete()->execute($key, $serverKey, $time, $body);
    }

    /**
     * Wire-format encoder + size-limit guard used by every backend's
     * {@code doStore()}/{@code doStoreMulti()}. Wraps {@see ValueCodec::encode()}
     * so each backend doesn't have to repeat the 9-argument call site, and
     * folds the {@code OPT_ITEM_SIZE_LIMIT} check next to it so a single
     * helper handles the two PECL error codes the encode path can surface
     * ({@code RES_PAYLOAD_FAILURE} / {@code RES_E2BIG}).
     *
     * Returns the encoded {@code [$payload, $flags]} pair on success, or
     * {@code null} after setting the appropriate {@code RES_*} code so the
     * caller can {@code return false;} (or skip the item in a multi-store).
     *
     * @return array{0: string, 1: int}|null
     */
    protected function encodeForStore(mixed $value): ?array
    {
        return $this->coordinators()->storeEncoder()->encode($value);
    }

    /**
     * @see ClientStoreEncoder::rejectIncompatibleConcatenation()
     */
    protected function rejectIncompatibleConcatenation(StoreMode $mode): bool
    {
        return $this->coordinators()->storeEncoder()->rejectIncompatibleConcatenation($mode);
    }

    /**
     * Sugar for the very common "set the result code and bubble back a
     * boolean to the public PECL surface" pattern. Returning {@code true}
     * is the success leg; {@see failResult()} is its mirror.
     */
    protected function okResult(int $code): bool
    {
        $this->setResult($code);

        return true;
    }

    protected function failResult(int $code): bool
    {
        $this->setResult($code);

        return false;
    }

    /**
     * Highest of {@code OPT_RECV_TIMEOUT}/{@code OPT_SEND_TIMEOUT} expressed in
     * fractional seconds. Backends that talk to a single socket (Redis,
     * Ignite) use this to derive a unified read/write deadline — memcached's
     * meta protocol keeps the two halves separate so it bypasses this helper.
     * Returns {@code 0.0} when neither option is set so callers can treat
     * the result as "use the underlying default".
     */
    protected function recvSendTimeoutSeconds(): float
    {
        $recv = $this->optionInt(self::OPT_RECV_TIMEOUT, 0);
        $send = $this->optionInt(self::OPT_SEND_TIMEOUT, 0);
        $ms = max($recv, $send);

        return $ms > 0 ? (float) $ms / 1000.0 : 0.0;
    }

    // -----------------------------------------------------------------------
    // Private internals
    // -----------------------------------------------------------------------

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, mixed>|false
     */
    #[\Override]
    protected function getMultiCommon(array $keys, ?string $serverKey, int $getFlags): array|false
    {
        return $this->coordinators()->multiKey()->getMulti($keys, $serverKey, $getFlags);
    }

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, bool|int>
     */
    private function deleteMultiCommon(array $keys, ?string $serverKey, int $time): array
    {
        return $this->coordinators()->multiKey()->deleteMulti($keys, $serverKey, $time);
    }
}
