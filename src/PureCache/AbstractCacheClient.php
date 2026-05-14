<?php

declare(strict_types=1);

namespace PureCache;

use PureCache\Internal\CacheEntry;
use PureCache\Internal\ClientCoreState;
use PureCache\Internal\ClientOptionApplier;
use PureCache\Internal\ClientOptionResult;
use PureCache\Internal\EncodingContext;
use PureCache\Internal\KeyFormatter;
use PureCache\Internal\OptionEnvironment;
use PureCache\Internal\ServerEndpoint;
use PureCache\Internal\StoreMode;

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
 */
abstract class AbstractCacheClient extends MemcachedConstants implements CacheClient, OptionEnvironment
{
    /** @var TState */
    protected ClientCoreState $core;

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
            $this->core = $reused;
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
            $this->registerPersistentState($pid, $this->core);
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

    /**
     * @return TState|null
     */
    abstract protected function lookupPersistentState(string $persistentId): ?ClientCoreState;

    /**
     * @param TState $state
     */
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

    abstract protected function doStore(string $key, mixed $value, int $expiration, StoreMode $mode, ?string $serverKey, ?string $casToken): bool;

    /**
     * @param array<mixed> $items
     */
    abstract protected function doStoreMulti(array $items, int $expiration, ?string $serverKey): bool;

    abstract protected function doTouch(string $key, int $expiration, ?string $serverKey): bool;

    abstract protected function doDelete(string $key, ?string $serverKey, int $time): bool;

    /**
     * Memcached-style arithmetic primitive. {@code $autoCreate} mirrors PECL's
     * "did the user pass initial/expiry?" detection — when {@code true}, the
     * concrete backend is expected to forward {@code initial_value} and
     * {@code expiry} so a missing key is autovivified instead of returning
     * {@code RES_NOTFOUND}.
     */
    abstract protected function doArith(string $key, int $offset, bool $decrement, ?string $serverKey, int $initialValue, int $expiry, bool $autoCreate = false): int|false;

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

    // -----------------------------------------------------------------------
    // PECL-shaped public surface
    // -----------------------------------------------------------------------

    #[\Override]
    public function getResultCode(): int
    {
        return $this->core->resultCode;
    }

    #[\Override]
    public function getResultMessage(): string
    {
        return $this->core->resultMessage;
    }

    protected function setResult(int $code, ?string $message = null): void
    {
        $this->core->resultCode = $code;
        $this->core->resultMessage = $message ?? $this->defaultMessage($code);
    }

    protected function defaultMessage(int $code): string
    {
        return match ($code) {
            self::RES_SUCCESS => 'SUCCESS',
            self::RES_END => 'END',
            self::RES_NOTFOUND => 'NOT FOUND',
            self::RES_DATA_EXISTS => 'DATA EXISTS',
            self::RES_NOTSTORED => 'NOT STORED',
            self::RES_FAILURE => 'FAILURE',
            self::RES_NO_SERVERS => 'NO SERVERS',
            self::RES_BAD_KEY_PROVIDED => 'BAD KEY',
            self::RES_PAYLOAD_FAILURE => 'PAYLOAD FAILURE',
            self::RES_NOT_SUPPORTED => 'NOT SUPPORTED',
            self::RES_INVALID_ARGUMENTS => 'INVALID ARGUMENTS',
            self::RES_INVALID_HOST_PROTOCOL => 'INVALID HOST PROTOCOL',
            self::RES_E2BIG => 'ITEM TOO BIG',
            self::RES_FETCH_NOTFINISHED => 'FETCH NOT FINISHED',
            self::RES_SOME_ERRORS => 'SOME ERRORS WERE REPORTED',
            self::RES_WRITE_FAILURE => 'WRITE FAILURE',
            self::RES_PARTIAL_READ => 'PARTIAL READ',
            self::RES_BUFFERED => 'BUFFERED',
            self::RES_SERVER_TEMPORARILY_DISABLED => 'SERVER TEMPORARILY DISABLED',
            self::RES_SERVER_MEMORY_ALLOCATION_FAILURE => 'SERVER MEMORY ALLOCATION FAILURE',
            self::RES_AUTH_PROBLEM => 'AUTH PROBLEM',
            self::RES_AUTH_FAILURE => 'AUTH FAILURE',
            self::RES_AUTH_CONTINUE => 'AUTH CONTINUE',
            default => 'UNKNOWN',
        };
    }

    #[\Override]
    public function addServer(string $host, int $port, int $weight = 0): bool
    {
        if ('' === $host) {
            $host = 'localhost';
        }

        if (0 === $port) {
            $port = $this->defaultPort();
        }

        if ($port < 0) {
            $this->setResult(self::RES_INVALID_ARGUMENTS);

            return false;
        }

        $this->core->selector->addServer(['host' => $host, 'port' => $port, 'weight' => $weight]);
        $this->onPoolInvalidated();
        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    /**
     * @param array<mixed> $servers
     */
    #[\Override]
    public function addServers(array $servers): bool
    {
        $validated = [];
        foreach ($servers as $s) {
            if (\is_array($s)) {
                if (array_is_list($s) && isset($s[0], $s[1])) {
                    $w = $s[2] ?? 0;
                    $validated[] = ['host' => $this->coerceString($s[0]), 'port' => $this->coerceInt($s[1]), 'weight' => $this->coerceInt($w)];
                    continue;
                }

                if (isset($s['host'], $s['port'])) {
                    $w = $s['weight'] ?? 0;
                    $validated[] = ['host' => $this->coerceString($s['host']), 'port' => $this->coerceInt($s['port']), 'weight' => $this->coerceInt($w)];
                    continue;
                }
            }

            $this->setResult(self::RES_FAILURE, 'invalid server entry');

            return false;
        }

        foreach ($validated as $server) {
            $this->core->selector->addServer($server);
        }

        $this->onPoolInvalidated();
        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    /**
     * @return list<array{host:string,port:int,type:string,weight:int}>
     */
    #[\Override]
    public function getServerList(): array
    {
        $out = [];
        foreach ($this->core->selector->getServers() as $s) {
            $out[] = ['host' => $s['host'], 'port' => $s['port'], 'type' => ServerEndpoint::listType($s['host']), 'weight' => $s['weight']];
        }

        return $out;
    }

    /**
     * @return array{host:string,port:int,weight:int}|false
     */
    #[\Override]
    public function getServerByKey(string $server_key): array|false
    {
        if (!$this->checkKeyInternal($server_key)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if ([] === $this->core->selector->getServers()) {
            $this->setResult(self::RES_NO_SERVERS);

            return false;
        }

        $idx = $this->core->selector->pickServerIndex($server_key);
        $s = $this->core->selector->getServers()[$idx];
        $this->setResult(self::RES_SUCCESS);

        return ['host' => $s['host'], 'port' => $s['port'], 'weight' => $s['weight']];
    }

    #[\Override]
    public function resetServerList(): bool
    {
        $this->core->selector->reset();
        $this->onPoolInvalidated();
        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    /**
     * @param array<mixed>      $host_map
     * @param array<mixed>|null $forward_map
     */
    #[\Override]
    public function setBucket(array $host_map, ?array $forward_map, int $replicas): bool
    {
        if ([] === $host_map) {
            trigger_error('Memcached::setBucket(): server map cannot be empty', \E_USER_WARNING);
            $this->setResult(self::RES_INVALID_ARGUMENTS);

            return false;
        }

        if (null !== $forward_map && \count($forward_map) !== \count($host_map)) {
            trigger_error('Memcached::setBucket(): forward_map length must match the server_map length', \E_USER_WARNING);
            $this->setResult(self::RES_INVALID_ARGUMENTS);

            return false;
        }

        if ($replicas < 0) {
            trigger_error('Memcached::setBucket(): replicas must be larger than zero', \E_USER_WARNING);
            $this->setResult(self::RES_INVALID_ARGUMENTS);

            return false;
        }

        if (!$this->bucketMapValuesAreValid($host_map) || (null !== $forward_map && !$this->bucketMapValuesAreValid($forward_map))) {
            trigger_error('Memcached::setBucket(): the map must contain positive integers', \E_USER_WARNING);
            $this->setResult(self::RES_INVALID_ARGUMENTS);

            return false;
        }

        $fwd = null !== $forward_map ? array_map($this->coerceInt(...), array_values($forward_map)) : null;
        $this->core->selector->setBucket(array_map($this->coerceInt(...), array_values($host_map)), $replicas, $fwd);
        $this->onPoolInvalidated();
        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    #[\Override]
    public function quit(): bool
    {
        try {
            $this->onPoolInvalidated();
        } catch (\Throwable $throwable) {
            $this->setResult(self::RES_WRITE_FAILURE, $throwable->getMessage());

            return false;
        }

        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    #[\Override]
    public function flushBuffers(): bool
    {
        try {
            $this->flushNetworkWrites();
        } catch (\Throwable $throwable) {
            $this->setResult(self::RES_WRITE_FAILURE, $throwable->getMessage());

            return false;
        }

        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    #[\Override]
    public function getLastErrorMessage(): string
    {
        return $this->core->resultMessage;
    }

    #[\Override]
    public function getLastErrorCode(): int
    {
        return $this->core->resultCode;
    }

    #[\Override]
    public function getLastErrorErrno(): int
    {
        return $this->core->lastErrorErrno;
    }

    /**
     * @return array{host:string,port:int,weight:int,type:string}|false
     */
    #[\Override]
    public function getLastDisconnectedServer(): array|false
    {
        return $this->core->lastDisconnectedServer ?? false;
    }

    #[\Override]
    public function getOption(int $option): mixed
    {
        // PECL maps OPT_LIBKETAMA_HASH onto MEMCACHED_BEHAVIOR_KETAMA_HASH,
        // whose setter writes to the same hashkit field that backs
        // MEMCACHED_BEHAVIOR_HASH. Empirically the net effect is that
        // getOption(OPT_LIBKETAMA_HASH) always tracks OPT_HASH — including
        // after OPT_LIBKETAMA_COMPATIBLE toggles that cascade OPT_HASH to
        // MD5 — while setOption(OPT_LIBKETAMA_HASH, …) leaves no observable
        // state behind. Folding the read into OPT_HASH here keeps that
        // read-alias contract centralised so the applier never has to
        // remember to write through to two slots.
        if (self::OPT_LIBKETAMA_HASH === $option) {
            $option = self::OPT_HASH;
        }

        $value = $this->core->options[$option] ?? null;
        if (\is_bool($value) && $this->optionReturnsIntegerBoolean($option)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    #[\Override]
    public function setOption(int $option, mixed $value): bool
    {
        $result = ClientOptionApplier::apply($this->core, $option, $value, $this);
        $this->setResult($result->code, $result->message);

        return $result->ok;
    }

    /**
     * @param array<mixed> $options
     */
    #[\Override]
    public function setOptions(array $options): bool
    {
        $ok = true;
        foreach ($options as $k => $v) {
            if (!\is_int($k)) {
                $ok = false;
                continue;
            }

            if (!$this->setOption($k, $v)) {
                $ok = false;
            }
        }

        return $ok;
    }

    #[\Override]
    public function isPersistent(): bool
    {
        return null !== $this->persistentId && '' !== $this->persistentId;
    }

    #[\Override]
    public function isPristine(): bool
    {
        return $this->pristine;
    }

    #[\Override]
    public function checkKey(string $key): bool
    {
        return $this->checkKeyInternal($this->prefixedKey($key));
    }

    #[\Override]
    public function setEncodingKey(#[\SensitiveParameter] string $key): bool
    {
        if ('' === $key) {
            $this->setResult(self::RES_INVALID_ARGUMENTS, 'encoding key must not be empty');

            return false;
        }

        if (!\extension_loaded('openssl')) {
            $this->setResult(self::RES_NOT_SUPPORTED, 'encoding requires ext-openssl');

            return false;
        }

        $mode = $this->optionInt(self::OPT_ENCODING_MODE, self::ENCODING_MODE_LIBMEMCACHED);
        $ctx = EncodingContext::fromUserKey($mode, $key);
        if (!$ctx instanceof EncodingContext) {
            $this->setResult(self::RES_INVALID_ARGUMENTS, 'invalid encoding mode');

            return false;
        }

        $this->core->encoding = $ctx;
        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    /**
     * Encoding context currently in force, or {@code null} when
     * {@link CacheClient::setEncodingKey()} has not been called (or was last
     * called with an unsupported mode). Backends pipe this value into
     * {@see Internal\ValueCodec::encode()} /
     * {@see Internal\ValueCodec::decode()} so encryption stays a
     * pure value-pipeline concern and never leaks into protocol handling.
     */
    protected function encodingContext(): ?EncodingContext
    {
        return $this->core->encoding;
    }

    #[\Override]
    public function setSaslAuthData(string $username, #[\SensitiveParameter] string $password): bool
    {
        $this->setResult(self::RES_NOT_SUPPORTED);

        return false;
    }

    /**
     * @return array<string, mixed>|false
     */
    #[\Override]
    public function getStats(?string $type = null): array|false
    {
        return $this->doGetStats($type);
    }

    /**
     * @return array<string, string>|false
     */
    #[\Override]
    public function getVersion(): array|false
    {
        return $this->doGetVersion();
    }

    #[\Override]
    public function flush(int $delay = 0): bool
    {
        return $this->doFlush($delay);
    }

    /**
     * @return list<string>|false
     */
    #[\Override]
    public function getAllKeys(): array|false
    {
        return $this->doGetAllKeys();
    }

    #[\Override]
    public function get(string $key, ?callable $cache_cb = null, int $get_flags = 0): mixed
    {
        $this->pristine = false;
        $this->flushNetworkWrites();

        $pk = $this->prefixedKey($key);
        if (!$this->checkKeyInternal($pk)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if (!$this->ensureServersAvailable()) {
            return false;
        }

        $value = $this->doGet($key, $pk, null, $get_flags);
        if (false !== $value || self::RES_NOTFOUND !== $this->core->resultCode) {
            return $value;
        }

        if (null !== $cache_cb) {
            return $this->invokeCacheCb($cache_cb, $key, null, $get_flags);
        }

        return false;
    }

    #[\Override]
    public function getByKey(string $server_key, string $key, ?callable $cache_cb = null, int $get_flags = 0): mixed
    {
        $this->pristine = false;
        $this->flushNetworkWrites();

        $pk = $this->prefixedKey($key);
        if (!$this->checkKeyInternal($pk) || !$this->checkKeyInternal($server_key)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if (!$this->ensureServersAvailable()) {
            return false;
        }

        $value = $this->doGet($key, $pk, $server_key, $get_flags);
        if (false !== $value || self::RES_NOTFOUND !== $this->core->resultCode) {
            return $value;
        }

        if (null !== $cache_cb) {
            return $this->invokeCacheCb($cache_cb, $key, $server_key, $get_flags);
        }

        return false;
    }

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, mixed>|false
     */
    #[\Override]
    public function getMulti(array $keys, int $get_flags = 0): array|false
    {
        return $this->getMultiCommon($keys, null, $get_flags);
    }

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, mixed>|false
     */
    #[\Override]
    public function getMultiByKey(string $server_key, array $keys, int $get_flags = 0): array|false
    {
        if (!$this->checkKeyInternal($server_key)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        return $this->getMultiCommon($keys, $server_key, $get_flags);
    }

    /**
     * @param array<mixed> $keys
     */
    #[\Override]
    public function getDelayed(array $keys, bool $with_cas = false, ?callable $value_cb = null): bool
    {
        return $this->enqueueDelayed(null, $keys, $with_cas, $value_cb);
    }

    /**
     * @param array<mixed> $keys
     */
    #[\Override]
    public function getDelayedByKey(string $server_key, array $keys, bool $with_cas = false, ?callable $value_cb = null): bool
    {
        if (!$this->checkKeyInternal($server_key)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        return $this->enqueueDelayed($server_key, $keys, $with_cas, $value_cb);
    }

    /**
     * @return array<string, mixed>|false
     */
    #[\Override]
    public function fetch(): array|false
    {
        if ([] === $this->core->delayedQueue && null === $this->core->delayedResults) {
            $this->setResult(self::RES_FETCH_NOTFINISHED);

            return false;
        }

        if (null === $this->core->delayedResults && !$this->primeDelayedResults()) {
            return false;
        }

        $current = $this->core->delayedResults ?? [];
        if ($this->core->delayedCursor >= \count($current) && [] !== $this->core->delayedQueue) {
            $this->core->delayedResults = null;
            $this->core->delayedCursor = 0;
            if (!$this->primeDelayedResults()) {
                return false;
            }

            $current = $this->core->delayedResults ?? [];
        }

        if ($this->core->delayedCursor >= \count($current)) {
            $this->setResult(self::RES_END);

            return false;
        }

        $row = $current[$this->core->delayedCursor++];
        $this->setResult(self::RES_SUCCESS);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>|false
     */
    #[\Override]
    public function fetchAll(): array|false
    {
        if ([] === $this->core->delayedQueue && null === $this->core->delayedResults) {
            $this->setResult(self::RES_FETCH_NOTFINISHED);

            return false;
        }

        if (null === $this->core->delayedResults && !$this->primeDelayedResults()) {
            return false;
        }

        $all = \array_slice($this->core->delayedResults ?? [], $this->core->delayedCursor);
        while ([] !== $this->core->delayedQueue) {
            $this->core->delayedResults = null;
            $this->core->delayedCursor = 0;
            if (!$this->primeDelayedResults()) {
                return false;
            }

            $all = array_merge($all, $this->core->delayedResults ?? []);
        }

        $this->core->delayedResults = [];
        $this->core->delayedQueue = [];
        $this->core->delayedCursor = 0;
        $this->setResult(self::RES_SUCCESS);

        return $all;
    }

    #[\Override]
    public function set(string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->doStore($key, $value, $expiration, StoreMode::Set, null, null);
    }

    #[\Override]
    public function setByKey(string $server_key, string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->doStore($key, $value, $expiration, StoreMode::Set, $server_key, null);
    }

    #[\Override]
    public function touch(string $key, int $expiration = 0): bool
    {
        return $this->doTouch($key, $expiration, null);
    }

    #[\Override]
    public function touchByKey(string $server_key, string $key, int $expiration = 0): bool
    {
        return $this->doTouch($key, $expiration, $server_key);
    }

    /**
     * @param array<mixed> $items
     */
    #[\Override]
    public function setMulti(array $items, int $expiration = 0): bool
    {
        foreach (array_keys($items) as $k) {
            if (!$this->checkKeyInternal($this->prefixedKey($this->keyToString($k)))) {
                $this->setResult(self::RES_BAD_KEY_PROVIDED);

                return false;
            }
        }

        if ([] !== $items && !$this->ensureServersAvailable()) {
            return false;
        }

        return $this->doStoreMulti($items, $expiration, null);
    }

    /**
     * @param array<mixed> $items
     */
    #[\Override]
    public function setMultiByKey(string $server_key, array $items, int $expiration = 0): bool
    {
        if (!$this->checkKeyInternal($server_key)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        foreach (array_keys($items) as $k) {
            if (!$this->checkKeyInternal($this->prefixedKey($this->keyToString($k)))) {
                $this->setResult(self::RES_BAD_KEY_PROVIDED);

                return false;
            }
        }

        if ([] !== $items && !$this->ensureServersAvailable()) {
            return false;
        }

        return $this->doStoreMulti($items, $expiration, $server_key);
    }

    #[\Override]
    public function add(string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->doStore($key, $value, $expiration, StoreMode::Add, null, null);
    }

    #[\Override]
    public function addByKey(string $server_key, string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->doStore($key, $value, $expiration, StoreMode::Add, $server_key, null);
    }

    #[\Override]
    public function replace(string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->doStore($key, $value, $expiration, StoreMode::Replace, null, null);
    }

    #[\Override]
    public function replaceByKey(string $server_key, string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->doStore($key, $value, $expiration, StoreMode::Replace, $server_key, null);
    }

    #[\Override]
    public function append(string $key, string $value): bool
    {
        return $this->doStore($key, $value, 0, StoreMode::Append, null, null);
    }

    #[\Override]
    public function appendByKey(string $server_key, string $key, string $value): bool
    {
        return $this->doStore($key, $value, 0, StoreMode::Append, $server_key, null);
    }

    #[\Override]
    public function prepend(string $key, string $value): bool
    {
        return $this->doStore($key, $value, 0, StoreMode::Prepend, null, null);
    }

    #[\Override]
    public function prependByKey(string $server_key, string $key, string $value): bool
    {
        return $this->doStore($key, $value, 0, StoreMode::Prepend, $server_key, null);
    }

    #[\Override]
    public function cas(string|int|float $cas_token, string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->doStore($key, $value, $expiration, StoreMode::Set, null, (string) $cas_token);
    }

    #[\Override]
    public function casByKey(string|int|float $cas_token, string $server_key, string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->doStore($key, $value, $expiration, StoreMode::Set, $server_key, (string) $cas_token);
    }

    #[\Override]
    public function delete(string $key, int $time = 0): bool
    {
        return $this->doDelete($key, null, $time);
    }

    #[\Override]
    public function deleteByKey(string $server_key, string $key, int $time = 0): bool
    {
        return $this->doDelete($key, $server_key, $time);
    }

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, bool|int>
     */
    #[\Override]
    public function deleteMulti(array $keys, int $time = 0): array
    {
        return $this->deleteMultiCommon($keys, null, $time);
    }

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, bool|int>
     */
    #[\Override]
    public function deleteMultiByKey(string $server_key, array $keys, int $time = 0): array
    {
        $keyStrings = $this->keyStrings($keys);
        if (!$this->checkKeyInternal($server_key)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return array_fill_keys($keyStrings, self::RES_BAD_KEY_PROVIDED);
        }

        return $this->deleteMultiCommon($keys, $server_key, $time);
    }

    #[\Override]
    public function increment(string $key, int $offset = 1, int $initial_value = 0, int $expiry = 0): int|false
    {
        return $this->doArith($key, $offset, false, null, $initial_value, $expiry, \func_num_args() >= 3);
    }

    #[\Override]
    public function decrement(string $key, int $offset = 1, int $initial_value = 0, int $expiry = 0): int|false
    {
        return $this->doArith($key, $offset, true, null, $initial_value, $expiry, \func_num_args() >= 3);
    }

    #[\Override]
    public function incrementByKey(string $server_key, string $key, int $offset = 1, int $initial_value = 0, int $expiry = 0): int|false
    {
        return $this->doArith($key, $offset, false, $server_key, $initial_value, $expiry, \func_num_args() >= 4);
    }

    #[\Override]
    public function decrementByKey(string $server_key, string $key, int $offset = 1, int $initial_value = 0, int $expiry = 0): int|false
    {
        return $this->doArith($key, $offset, true, $server_key, $initial_value, $expiry, \func_num_args() >= 4);
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
        return null;
    }

    // -----------------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------------

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
        if ([] !== $this->core->selector->getServers()) {
            return true;
        }

        $this->setResult(self::RES_NO_SERVERS);

        return false;
    }

    /**
     * Pick the routing index for {@code $key}, honouring an optional server-key override.
     */
    protected function pickServerIndex(?string $serverKey, string $key): int
    {
        return null !== $serverKey
            ? $this->core->selector->pickServerIndex($serverKey)
            : $this->core->selector->pickServerIndex($this->routingKey($key));
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
        $byServer = [];
        if (null === $serverKey) {
            foreach ($keys as $ks) {
                $idx = $this->core->selector->pickServerIndex($this->routingKey($ks));
                $byServer[$idx][] = [$ks, $this->prefixedKey($ks)];
            }

            return $byServer;
        }

        $idx = $this->core->selector->pickServerIndex($serverKey);
        foreach ($keys as $ks) {
            $byServer[$idx][] = [$ks, $this->prefixedKey($ks)];
        }

        return $byServer;
    }

    /**
     * Boilerplate-free pre-flight for an operation routed by a single PECL key.
     *
     * Validates the prefixed key + the (optional) server-key, ensures the server
     * pool is non-empty, picks the routing index, and flips {@code pristine = false}.
     * On any pre-flight failure the appropriate {@code RES_*} is set and the
     * closure is not invoked; on a thrown protocol-level exception
     * {@see recordServerFailure()} is invoked and the result is set to
     * {@code RES_FAILURE}. In both failure cases the supplied {@code $failureValue}
     * is returned so the caller can use the same sentinel as the PECL surface.
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
    ): mixed {
        $this->pristine = false;
        $pk = $this->prefixedKey($key);
        if (!$this->checkKeyInternal($pk) || (null !== $serverKey && !$this->checkKeyInternal($serverKey))) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return $failureValue;
        }

        if (!$this->ensureServersAvailable()) {
            return $failureValue;
        }

        $idx = $this->pickServerIndex($serverKey, $key);

        try {
            return $body($idx, $pk);
        } catch (\Throwable $throwable) {
            $this->recordServerFailure($idx, $throwable);
            $this->setResult(self::RES_FAILURE, $throwable->getMessage());

            return $failureValue;
        }
    }

    protected function recordServerFailure(?int $serverIndex, \Throwable $throwable): void
    {
        $this->core->lastErrorErrno = $throwable->getCode();
        if (null === $serverIndex) {
            return;
        }

        $server = $this->core->selector->getServers()[$serverIndex] ?? null;
        if (null === $server) {
            return;
        }

        $this->core->lastDisconnectedServer = [
            'host' => $server['host'],
            'port' => $server['port'],
            'weight' => $server['weight'],
            'type' => ServerEndpoint::listType($server['host']),
        ];
    }

    /**
     * @return string the key in canonical string form, or {@code ''} (which
     *                {@see checkKeyInternal()} will subsequently reject as
     *                {@code RES_BAD_KEY_PROVIDED}) if {@code $key} is not a
     *                memcached-compatible scalar or {@see \Stringable}
     */
    protected function keyToString(mixed $key): string
    {
        if (\is_string($key)) {
            return $key;
        }

        if (\is_int($key) || \is_float($key) || null === $key || \is_bool($key)) {
            return (string) $key;
        }

        if ($key instanceof \Stringable) {
            return (string) $key;
        }

        $this->setResult(
            self::RES_BAD_KEY_PROVIDED,
            'key must be a string, got '.get_debug_type($key),
        );

        return '';
    }

    /**
     * @param array<mixed> $keys
     *
     * @return list<string>
     */
    protected function keyStrings(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[] = $this->keyToString($key);
        }

        return $out;
    }

    protected function casValue(?string $cas): int|string
    {
        if (null === $cas || '' === $cas) {
            return 0;
        }

        if ((string) (int) $cas !== $cas) {
            return $cas;
        }

        return (int) $cas;
    }

    /**
     * Shape a successful read into either the decoded value or the PECL
     * {@code GET_EXTENDED} array ({@code ['value' => …, 'cas' => …, 'flags' => …]}).
     */
    protected function valueForGetFlags(CacheEntry $entry, int $getFlags): mixed
    {
        if (($getFlags & self::GET_EXTENDED) !== 0) {
            return [
                'value' => $entry->value,
                'cas' => $entry->cas,
                'flags' => $entry->userFlags,
            ];
        }

        return $entry->value;
    }

    /**
     * Convert a cache hit into a {@code getDelayed}/{@code fetch} row.
     *
     * @return array<string, mixed>
     */
    protected function delayedEntry(string $key, CacheEntry $entry, bool $withCas): array
    {
        $row = ['key' => $key, 'value' => $entry->value];
        if ($withCas) {
            $row['cas'] = $entry->cas;
            $row['flags'] = $entry->userFlags;
        }

        return $row;
    }

    protected function acceptDeleteTime(int $time): bool
    {
        if ($time < 0) {
            $this->setResult(self::RES_INVALID_ARGUMENTS, 'delete time must be non-negative');

            return false;
        }

        if ($time > 0) {
            $this->setResult(self::RES_NOT_SUPPORTED, 'delayed delete is not supported by the meta protocol');

            return false;
        }

        return true;
    }

    protected function optionReturnsIntegerBoolean(int $option): bool
    {
        return \in_array($option, [
            self::OPT_BUFFER_WRITES,
            self::OPT_HASH_WITH_PREFIX_KEY,
            self::OPT_LIBKETAMA_COMPATIBLE,
            self::OPT_NO_BLOCK,
            self::OPT_NOREPLY,
            self::OPT_TCP_KEEPALIVE,
            self::OPT_TCP_NODELAY,
            self::OPT_VERIFY_KEY,
        ], true);
    }

    // -----------------------------------------------------------------------
    // Private internals
    // -----------------------------------------------------------------------

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, mixed>|false
     */
    private function getMultiCommon(array $keys, ?string $serverKey, int $getFlags): array|false
    {
        $this->pristine = false;
        $this->flushNetworkWrites();

        if ([] === $keys) {
            $this->setResult(self::RES_SUCCESS);

            return [];
        }

        $keyStrings = $this->keyStrings($keys);
        foreach ($keyStrings as $ks) {
            if (!$this->checkKeyInternal($this->prefixedKey($ks))) {
                $this->setResult(self::RES_BAD_KEY_PROVIDED);

                return false;
            }
        }

        if (!$this->ensureServersAvailable()) {
            return false;
        }

        $found = $this->doGetMulti($keyStrings, $serverKey, $getFlags);
        if (false === $found) {
            return false;
        }

        // Preserve any RES_SOME_ERRORS that doGetMulti may have set when some
        // server shards failed but at least one returned results. Defaulting
        // to RES_SUCCESS here would silently swallow partial-failure signals
        // and break PECL parity with multi-server getMulti fan-out.
        if (self::RES_SOME_ERRORS !== $this->getResultCode()) {
            $this->setResult(self::RES_SUCCESS);
        }

        if (($getFlags & self::GET_PRESERVE_ORDER) !== 0) {
            $ordered = [];
            foreach ($keyStrings as $ks) {
                $ordered[$ks] = $found[$ks] ?? null;
            }

            return $ordered;
        }

        return $found;
    }

    /**
     * @param array<mixed> $keys
     */
    private function enqueueDelayed(?string $serverKey, array $keys, bool $withCas, ?callable $valueCb): bool
    {
        if ([] === $keys) {
            $this->setResult(self::RES_SUCCESS);

            return true;
        }

        foreach ($keys as $k) {
            if (!$this->checkKeyInternal($this->prefixedKey($this->keyToString($k)))) {
                $this->setResult(self::RES_BAD_KEY_PROVIDED);

                return false;
            }
        }

        if (!$this->ensureServersAvailable()) {
            return false;
        }

        if (null !== $valueCb) {
            $this->pristine = false;

            return $this->doGetDelayedValueCallback($this->keyStrings($keys), $serverKey, $withCas, $valueCb);
        }

        $this->core->delayedQueue[] = [
            'keys' => $this->keyStrings($keys),
            'serverKey' => $serverKey,
            'withCas' => $withCas,
        ];
        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    private function primeDelayedResults(): bool
    {
        $batch = array_shift($this->core->delayedQueue);
        if (null === $batch) {
            $this->core->delayedResults = [];

            return true;
        }

        $this->flushNetworkWrites();
        if (!$this->ensureServersAvailable()) {
            $this->core->delayedResults = [];
            $this->core->delayedCursor = 0;

            return false;
        }

        $results = $this->doFetchBatch($batch['keys'], $batch['serverKey'], $batch['withCas']);
        if (false === $results) {
            $this->core->delayedResults = [];
            $this->core->delayedCursor = 0;

            return false;
        }

        $this->core->delayedResults = $results;
        $this->core->delayedCursor = 0;

        return true;
    }

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, bool|int>
     */
    private function deleteMultiCommon(array $keys, ?string $serverKey, int $time): array
    {
        $keyStrings = $this->keyStrings($keys);
        foreach ($keys as $k) {
            if (!$this->checkKeyInternal($this->prefixedKey($this->keyToString($k)))) {
                $this->setResult(self::RES_BAD_KEY_PROVIDED);

                return array_fill_keys($keyStrings, self::RES_BAD_KEY_PROVIDED);
            }
        }

        if (!$this->acceptDeleteTime($time)) {
            return array_fill_keys($keyStrings, $this->core->resultCode);
        }

        $out = [];
        foreach ($keys as $k) {
            $ks = $this->keyToString($k);
            $ok = $this->doDelete($ks, $serverKey, 0);
            $out[$ks] = $ok ? true : $this->core->resultCode;
        }

        return $out;
    }

    /**
     * Run the legacy {@code cache_cb(self, key, value, expiration[, cas])} fallback used
     * by PECL when a {@see get()} misses. Stores the produced value (when truthy) and
     * re-reads through the normal path so flag handling is uniform.
     *
     * @param callable(CacheClient $client, string $key, mixed &$value, int &$expiration, float &$cas):bool $cacheCb
     */
    private function invokeCacheCb(callable $cacheCb, string $key, ?string $serverKey, int $getFlags): mixed
    {
        $value = null;
        $expirationRef = 0;
        $casRef = 0.0;
        $accepted = $cacheCb($this, $key, $value, $expirationRef, $casRef);
        if (true !== $accepted || null === $value) {
            $this->setResult(self::RES_NOTFOUND);

            return false;
        }

        $expiration = \is_int($expirationRef) ? $expirationRef : 0;

        if (null === $serverKey) {
            $this->set($key, $value, $expiration);

            return $this->get($key, null, $getFlags);
        }

        $this->setByKey($serverKey, $key, $value, $expiration);

        return $this->getByKey($serverKey, $key, null, $getFlags);
    }

    /**
     * @param array<mixed> $map
     */
    private function bucketMapValuesAreValid(array $map): bool
    {
        foreach ($map as $value) {
            if ($this->coerceInt($value) < 0) {
                return false;
            }
        }

        return true;
    }

    private function coerceString(mixed $value): string
    {
        if (\is_scalar($value) || null === $value) {
            return (string) $value;
        }

        return '';
    }

    private function coerceInt(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value) || \is_bool($value)) {
            return (int) $value;
        }

        if (\is_string($value)) {
            return (int) $value;
        }

        return 0;
    }
}
