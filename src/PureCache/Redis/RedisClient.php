<?php

declare(strict_types=1);

namespace PureCache\Redis;

use PureCache\AbstractCacheClient;
use PureCache\Internal\CacheEntry;
use PureCache\Internal\Expiration;
use PureCache\Internal\KeyFormatter;
use PureCache\Internal\PersistentStateRegistry;
use PureCache\Internal\StoreMode;
use PureCache\Internal\ValueCodec;
use PureCache\Redis\Internal\RedisInfoReplyFlatten;

/**
 * PECL {@code \Memcached}-shaped client backed by Redis instances over plain RESP2.
 *
 * Inherits the entire user-facing surface from {@see AbstractCacheClient}; this subclass
 * only implements the Redis-specific I/O primitives (HGETALL reads, EVALSHA-driven
 * Memcached-style mutations, server-by-server pipelining for batch reads, etc.) and
 * the {@code persistent_id} pool keyed by `persistent_id`.
 *
 * @extends AbstractCacheClient<RedisClientState>
 */
final class RedisClient extends AbstractCacheClient
{
    /** @use PersistentStateRegistry<RedisClientState> */
    use PersistentStateRegistry;

    public const string ITEM_KEY_PREFIX = 'pm:v1:';

    /** @var list<array{serverIndex:int, fn:callable(NativeRedisClient):void}> */
    private array $writeBuffer = [];

    #[\Override]
    protected function createState(?string $persistentId): RedisClientState
    {
        return RedisClientState::createFresh($persistentId);
    }

    #[\Override]
    protected function defaultPort(): int
    {
        return 6379;
    }

    /**
     * Redis keys are bounded by the 512 MB string size cap, but anything beyond
     * a few kilobytes hurts the {@code HGETALL}/{@code SCAN} ergonomics that
     * back the PureCache wire format, so we keep the practical ceiling small
     * enough to fit comfortably in a single RESP frame.
     */
    #[\Override]
    public function maxKeyLength(): int
    {
        return 65_536;
    }

    #[\Override]
    public function onPoolInvalidated(): void
    {
        $this->disconnectRedis();
    }

    /**
     * Options whose libmemcached/PECL semantics have no analogue on the
     * RESP2 client we ship — either the surrounding TCP knob is never wired
     * up in {@see NativeRedisClient::connect()} (KEEPALIVE family, socket
     * buffer sizes, TCP_CORK), or the option only makes sense for
     * libmemcached's internal IO scheduler (POLL_TIMEOUT, IO watermarks,
     * key prefetch, DNS cache). Setting them quietly to {@code RES_SUCCESS}
     * would mean accepting a value that demonstrably does nothing — PECL's
     * own contract is to surface {@code RES_NOT_SUPPORTED} for behaviours
     * the underlying client can't honour, so we do the same.
     *
     * Notably *kept* as supported: {@code OPT_TCP_NODELAY} (Redis transport
     * pins it to {@code true} already, matching the typical request),
     * {@code OPT_NO_BLOCK} (kept for cross-backend setOption() parity even
     * though our reader is blocking), and every routing / replication /
     * serialization / encoding / timeout option that PureCache implements
     * uniformly for all backends.
     */
    private const array UNSUPPORTED_OPTIONS = [
        self::OPT_TCP_KEEPALIVE => true,
        self::OPT_TCP_KEEPIDLE => true,
        self::OPT_SOCKET_SEND_SIZE => true,
        self::OPT_SOCKET_RECV_SIZE => true,
        self::OPT_CORK => true,
        self::OPT_POLL_TIMEOUT => true,
        self::OPT_IO_BYTES_WATERMARK => true,
        self::OPT_IO_MSG_WATERMARK => true,
        self::OPT_IO_KEY_PREFETCH => true,
        self::OPT_CACHE_LOOKUPS => true,
    ];

    #[\Override]
    public function isUnsupportedOption(int $option): bool
    {
        return isset(self::UNSUPPORTED_OPTIONS[$option]);
    }

    #[\Override]
    public function unsupportedOptionMessage(): string
    {
        return 'option is not supported by the Redis-backed client';
    }

    #[\Override]
    protected function flushNetworkWrites(): void
    {
        $this->flushWriteBuffer();
    }

    private function st(): RedisClientState
    {
        return $this->state();
    }

    // -----------------------------------------------------------------------
    // Read paths
    // -----------------------------------------------------------------------

    #[\Override]
    protected function doGet(string $key, string $prefixedKey, ?string $serverKey, int $getFlags): mixed
    {
        return $this->executeKeyed($key, $serverKey, function (int $idx, string $pk) use ($getFlags): mixed {
            $entry = $this->readEntry($pk, $idx);
            if (!$entry instanceof CacheEntry) {
                // {@see cacheEntryFromHash()} already sets RES_PAYLOAD_FAILURE
                // when decryption/deserialization throws; we mustn't overwrite
                // that with RES_NOTFOUND, otherwise crypto misconfigurations
                // would look like ordinary cache misses to the caller.
                if (self::RES_PAYLOAD_FAILURE !== $this->getResultCode()) {
                    $this->setResult(self::RES_NOTFOUND);
                }

                return false;
            }

            $this->setResult(self::RES_SUCCESS);

            return $this->valueForGetFlags($entry, $getFlags);
        }, forRead: true);
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, mixed>|false
     */
    #[\Override]
    protected function doGetMulti(array $keys, ?string $serverKey, int $getFlags): array|false
    {
        try {
            $found = [];
            $byServer = $this->groupKeysByServer($keys, $serverKey);
            foreach ($byServer as $idx => $pairs) {
                $entries = $this->readEntriesPipelined(array_map(static fn (array $p): string => $p[1], $pairs), $idx);
                foreach ($pairs as $i => [$orig]) {
                    $entry = $entries[$i] ?? null;
                    if (null !== $entry) {
                        $found[$orig] = $this->valueForGetFlags($entry, $getFlags);
                    }
                }
            }

            return $found;
        } catch (\Throwable $throwable) {
            $this->setResult(self::RES_FAILURE, $throwable->getMessage());

            return false;
        }
    }

    /**
     * @param list<string> $keys
     *
     * @return list<array<string, mixed>>|false
     */
    #[\Override]
    protected function doFetchBatch(array $keys, ?string $serverKey, bool $withCas): array|false
    {
        try {
            $results = [];
            $byServer = $this->groupKeysByServer($keys, $serverKey);
            foreach ($byServer as $idx => $pairs) {
                $entries = $this->readEntriesPipelined(array_map(static fn (array $p): string => $p[1], $pairs), $idx);
                foreach ($pairs as $i => [$orig]) {
                    $entry = $entries[$i] ?? null;
                    if (null === $entry) {
                        continue;
                    }

                    $results[] = $this->delayedEntry($orig, $entry, $withCas);
                }
            }

            return $results;
        } catch (\Throwable $throwable) {
            $this->setResult(self::RES_FAILURE, $throwable->getMessage());

            return false;
        }
    }

    /**
     * @param list<string>                              $keys
     * @param callable(self, array<string, mixed>):void $valueCb
     */
    #[\Override]
    protected function doGetDelayedValueCallback(array $keys, ?string $serverKey, bool $withCas, callable $valueCb): bool
    {
        $this->flushWriteBuffer();
        try {
            $byServer = $this->groupKeysByServer($keys, $serverKey);
            foreach ($byServer as $idx => $pairs) {
                $entries = $this->readEntriesPipelined(array_map(static fn (array $p): string => $p[1], $pairs), $idx);
                foreach ($pairs as $i => [$orig]) {
                    $entry = $entries[$i] ?? null;
                    if (null === $entry) {
                        continue;
                    }

                    $valueCb($this, $this->delayedEntry($orig, $entry, $withCas));
                }
            }
        } catch (\Throwable $throwable) {
            $this->setResult(self::RES_FAILURE, $throwable->getMessage());

            return false;
        }

        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    // -----------------------------------------------------------------------
    // Write paths
    // -----------------------------------------------------------------------

    #[\Override]
    protected function doStore(string $key, mixed $value, int $expiration, StoreMode $mode, ?string $serverKey, ?string $casToken): bool
    {
        if (!$this->rejectIncompatibleConcatenation($mode)) {
            return false;
        }

        $pk = $this->prefixedKey($key);
        if (!$this->checkKeyInternal($pk)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if (null !== $serverKey && !$this->checkKeyInternal($serverKey)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if (!$this->ensureServersAvailable()) {
            return false;
        }

        if ($mode->isConcatenation()) {
            return $this->storeAppendOrPrepend($pk, $value, $mode, $serverKey, $key);
        }

        $encoded = $this->encodeForStore($value);
        if (null === $encoded) {
            return false;
        }

        [$payload, $flags] = $encoded;

        if (!$this->shouldBufferNoReplyWrite()) {
            $this->flushWriteBuffer();
        }

        $rk = $this->itemRedisKey($pk);
        $ttl = $this->ttlSeconds($expiration);

        $fn = match ($mode) {
            StoreMode::Add => $this->makeAddClosure($rk, $payload, $flags, $ttl),
            StoreMode::Replace => $this->makeReplaceClosure($rk, $payload, $flags, $ttl),
            default => $this->makeCasSetClosure($rk, $payload, $flags, $ttl, $casToken ?? ''),
        };

        return $this->retryStoreOnFailure($serverKey, $key, function (int $idx) use ($fn): bool {
            try {
                return $this->runStoreFn($fn, $idx);
            } catch (\Throwable $throwable) {
                $this->recordServerFailure($idx, $throwable);
                $this->setResult(self::RES_FAILURE, $throwable->getMessage());

                return false;
            }
        });
    }

    /**
     * Pipelined {@code setMulti} via EVALSHA(LUA_CAS_SET) per-server: one TCP
     * write that batches all keys for the same Redis backend, then a single
     * receive loop drains the replies.
     *
     * Honours {@code OPT_NUMBER_OF_REPLICAS}: each key's prepared command is
     * appended to the primary's batch *and* to each replica's batch, with the
     * primary slot flagged so only its reply (or its server's transport error)
     * influences {@code RES_SUCCESS}/{@code RES_SOME_ERRORS}. Replica failures
     * are recorded against the failure tracker but never surface as
     * {@code RES_SOME_ERRORS}, matching the single-key fan-out contract
     * established by {@see writeFanout()}.
     *
     * Falls back to per-item error reporting for any item whose value fails
     * encoding (bad compression input, oversized payload, etc.) so PECL's
     * {@code RES_SOME_ERRORS} semantics remain intact.
     *
     * @param array<mixed> $items
     */
    #[\Override]
    protected function doStoreMulti(array $items, int $expiration, ?string $serverKey): bool
    {
        if ([] === $items) {
            $this->setResult(self::RES_SUCCESS);

            return true;
        }

        $ok = true;

        // Loop-invariant for all items in this call: same script SHA, same TTL window.
        $scriptSha = sha1(RedisItemScripts::LUA_CAS_SET);
        $ttlArg = (string) ($this->ttlSeconds($expiration) ?? 0);

        /** @var array<int, list<array{cmd: list<string>, key: string, primary: bool}>> $byServer */
        $byServer = [];
        foreach ($items as $key => $value) {
            $keyString = (string) $key;
            $pk = $this->prefixedKey($keyString);
            if (!$this->checkKeyInternal($pk)) {
                $this->setResult(self::RES_BAD_KEY_PROVIDED);
                $ok = false;
                continue;
            }

            $encoded = $this->encodeForStore($value);
            if (null === $encoded) {
                $ok = false;
                continue;
            }

            [$payload, $flags] = $encoded;

            $targets = $this->fanoutTargets($serverKey, $keyString);
            if (null === $targets) {
                $ok = false;
                continue;
            }

            $rk = $this->itemRedisKey($pk);
            $cmd = ['EVALSHA', $scriptSha, '1', $rk, $payload, (string) $flags, $ttlArg, ''];

            $primaryIdx = $targets['primary'];
            $byServer[$primaryIdx][] = ['cmd' => $cmd, 'key' => $keyString, 'primary' => true];
            // Dedup replicas against the primary and against each other: a
            // single Redis connection must never see the same EVALSHA twice
            // per item, otherwise we'd corrupt CAS and double the work.
            $seenReplicas = [$primaryIdx => true];
            foreach ($targets['replicas'] as $replicaIdx) {
                if (isset($seenReplicas[$replicaIdx])) {
                    continue;
                }

                $seenReplicas[$replicaIdx] = true;
                $byServer[$replicaIdx][] = ['cmd' => $cmd, 'key' => $keyString, 'primary' => false];
            }
        }

        $fatalMessage = null;
        foreach ($byServer as $idx => $batch) {
            $hasPrimary = false;
            foreach ($batch as $entry) {
                if ($entry['primary']) {
                    $hasPrimary = true;

                    break;
                }
            }

            try {
                $redis = $this->redisForServerIndex($idx);
                $commands = array_map(static fn (array $b): array => $b['cmd'], $batch);
                $replies = $this->pipelineWithScriptFallback($redis, $commands, RedisItemScripts::LUA_CAS_SET);

                foreach ($replies as $position => $reply) {
                    $isPrimarySlot = $batch[$position]['primary'];
                    if ($reply instanceof \Throwable) {
                        if ($isPrimarySlot) {
                            $this->setResult(self::RES_FAILURE, $reply->getMessage());
                            $ok = false;
                        }

                        continue;
                    }

                    try {
                        [$status] = RedisItemScripts::decodePairReply($reply);
                    } catch (\Throwable $throwable) {
                        if ($isPrimarySlot) {
                            $this->setResult(self::RES_FAILURE, $throwable->getMessage());
                            $ok = false;
                        }

                        continue;
                    }

                    if ($isPrimarySlot && RedisItemScripts::STATUS_OK !== $status) {
                        $ok = false;
                    }
                }

                $this->recordServerSuccess($idx);
            } catch (\Throwable $throwable) {
                $this->recordServerFailure($idx, $throwable);
                if ($hasPrimary) {
                    $ok = false;
                    $fatalMessage = $throwable->getMessage();
                }
            }
        }

        if (null !== $fatalMessage) {
            $this->setResult(self::RES_FAILURE, $fatalMessage);
        } else {
            $this->setResult($ok ? self::RES_SUCCESS : self::RES_SOME_ERRORS);
        }

        return $ok;
    }

    /**
     * Wraps {@see NativeRedisClient::pipeline()} with a lazy {@code SCRIPT LOAD}
     * recovery: any {@code NOSCRIPT}-failed slot is re-issued after the server
     * has been told to cache {@code $script}, while every other slot — success
     * or other error — is kept verbatim.
     *
     * Unlike a "retry the whole batch" strategy, this re-runs only the commands
     * the server refused, so callers don't have to assume their commands are
     * idempotent. {@code $script} must be the source of every EVALSHA in
     * {@code $commands} (the helper has no way to tell different scripts
     * apart), which holds for every current caller because each batch sticks
     * to one Lua program.
     *
     * @param list<list<string>> $commands
     *
     * @return list<mixed>
     */
    private function pipelineWithScriptFallback(NativeRedisClient $redis, array $commands, string $script): array
    {
        if ([] === $commands) {
            return [];
        }

        $replies = $redis->pipeline($commands);

        $retrySlots = [];
        foreach ($replies as $position => $reply) {
            if ($reply instanceof RedisCommandException && str_starts_with($reply->getMessage(), 'NOSCRIPT')) {
                $retrySlots[] = $position;
            }
        }

        if ([] === $retrySlots) {
            return $replies;
        }

        $redis->executeRaw(['SCRIPT', 'LOAD', $script]);

        $retryCommands = [];
        foreach ($retrySlots as $position) {
            $retryCommands[] = $commands[$position];
        }

        $retryReplies = $redis->pipeline($retryCommands);
        foreach ($retrySlots as $i => $position) {
            $replies[$position] = $retryReplies[$i];
        }

        // We only overwrote existing list-shaped indices, but PHPStan can't
        // prove that on its own — array_values() is the cheap way to reassure
        // the type checker that the result is still a list<mixed>.
        return array_values($replies);
    }

    #[\Override]
    protected function doTouch(string $key, int $expiration, ?string $serverKey): bool
    {
        if (!$this->shouldBufferNoReplyWrite()) {
            $this->flushWriteBuffer();
        }

        return $this->executeKeyed($key, $serverKey, function (int $idx, string $pk) use ($expiration): bool {
            $ttl = $this->ttlSeconds($expiration);
            $rk = $this->itemRedisKey($pk);
            $fn = static function (NativeRedisClient $r) use ($rk, $ttl): void {
                $reply = $r->evalScript(RedisItemScripts::LUA_TOUCH, [$rk], [
                    (string) ($ttl ?? 0),
                ]);
                if (1 !== $reply) {
                    throw new RedisClientStoreException(RedisItemScripts::STATUS_NOT_FOUND);
                }
            };

            if ($this->shouldBufferNoReplyWrite()) {
                $this->enqueueWrite($fn, $idx);
                $this->setResult(self::RES_SUCCESS);

                return true;
            }

            try {
                $fn($this->redisForServerIndex($idx));
                $this->setResult(self::RES_SUCCESS);

                return true;
            } catch (RedisClientStoreException $redisClientStoreException) {
                if (RedisItemScripts::STATUS_NOT_FOUND === $redisClientStoreException->outcome) {
                    $this->setResult(self::RES_NOTFOUND);

                    return false;
                }

                throw $redisClientStoreException;
            }
        }, fanoutWrite: true);
    }

    #[\Override]
    protected function doDelete(string $key, ?string $serverKey, int $time): bool
    {
        return $this->executeDelete($key, $serverKey, $time, function (string $pk) use ($serverKey, $key): bool {
            if (!$this->shouldBufferNoReplyWrite()) {
                $this->flushWriteBuffer();
            }

            return $this->writeFanout($serverKey, $key, function (int $idx) use ($pk): bool {
                try {
                    $rk = $this->itemRedisKey($pk);

                    if ($this->useNoReply()) {
                        // Fire-and-forget: enqueueWrite() already routes through
                        // the OPT_BUFFER_WRITES check (buffer when on, dispatch
                        // immediately when off).
                        $this->enqueueWrite(static function (NativeRedisClient $r) use ($rk): void {
                            $r->del([$rk]);
                        }, $idx);

                        return $this->okResult(self::RES_SUCCESS);
                    }

                    // With-reply path: the pre-flush above already drained any
                    // pending buffered writes, so call DEL synchronously and
                    // inspect the count without re-entering the buffer machinery.
                    $n = $this->redisForServerIndex($idx)->del([$rk]);
                    if (0 === $n) {
                        return $this->failResult(self::RES_NOTFOUND);
                    }

                    return $this->okResult(self::RES_SUCCESS);
                } catch (\Throwable $throwable) {
                    $this->recordServerFailure($idx, $throwable);
                    $this->setResult(self::RES_FAILURE, $throwable->getMessage());

                    return false;
                }
            });
        });
    }

    #[\Override]
    protected function doArith(string $key, int $offset, bool $decrement, ?string $serverKey, int $initialValue, int $expiry, bool $autoCreate = false): int|false
    {
        if ($offset < 0) {
            trigger_error('offset cannot be a negative value', \E_USER_WARNING);
            $this->setResult(self::RES_INVALID_ARGUMENTS);

            return false;
        }

        $this->flushWriteBuffer();

        return $this->executeKeyed($key, $serverKey, function (int $idx, string $pk) use ($offset, $decrement, $initialValue, $expiry, $autoCreate): int|false {
            $rk = $this->itemRedisKey($pk);
            $initialArg = $autoCreate ? (string) $initialValue : '';
            $ttlArg = $autoCreate ? (string) ($this->ttlSeconds($expiry) ?? 0) : '0';
            $typeLongFlag = (string) ValueCodec::TYPE_LONG;

            $reply = $this->redisForServerIndex($idx)->evalScript(
                RedisItemScripts::LUA_ARITH,
                [$rk],
                [(string) $offset, $decrement ? 'D' : 'I', $initialArg, $ttlArg, $typeLongFlag],
            );
            [$status, $newValue] = RedisItemScripts::decodeArithReply($reply);
            if (RedisItemScripts::STATUS_OK === $status) {
                $this->setResult(self::RES_SUCCESS);

                return (int) $newValue;
            }

            if (RedisItemScripts::STATUS_NOT_FOUND === $status) {
                $this->setResult(self::RES_NOTFOUND);

                return false;
            }

            $this->setResult(self::RES_NOTSTORED);

            return false;
        });
    }

    // -----------------------------------------------------------------------
    // Stats / version / flush / getAllKeys
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>|false
     */
    #[\Override]
    protected function doGetStats(?string $type): array|false
    {
        $this->flushWriteBuffer();
        if (!$this->ensureServersAvailable()) {
            return false;
        }

        $result = $this->collectFromServers(function (int $i) use ($type): array {
            $r = $this->redisForServerIndex($i);
            if (null === $type || '' === $type) {
                return RedisStatsAsMemcached::general($r);
            }

            if ('items' === $type) {
                return RedisStatsAsMemcached::items($r, self::ITEM_KEY_PREFIX.'*');
            }

            if ('slabs' === $type) {
                $scan = RedisStatsAsMemcached::scanCountAndFirstKey($r, self::ITEM_KEY_PREFIX.'*', 100_000);

                return RedisStatsAsMemcached::slabs($r, $scan['count']);
            }

            if ('sizes' === $type) {
                return RedisStatsAsMemcached::sizes();
            }

            $parsed = $this->redisInfoAssoc($r, $type);
            if (!isset($parsed['version'])) {
                $parsed['version'] = $parsed['redis_version'] ?? ($parsed['Version'] ?? 'unknown');
            }

            return $parsed;
        }, false);

        $this->setResult($result['allOk'] ? self::RES_SUCCESS : self::RES_SOME_ERRORS);

        return $result['values'];
    }

    /**
     * @return array<string, string>|false
     */
    #[\Override]
    protected function doGetVersion(): array|false
    {
        $this->flushWriteBuffer();
        if (!$this->ensureServersAvailable()) {
            return false;
        }

        $result = $this->collectFromServers(function (int $i): string {
            $info = $this->redisInfoAssoc($this->redisForServerIndex($i), 'server');

            return $info['redis_version'] ?? '';
        }, '');

        $this->setResult($result['allOk'] ? self::RES_SUCCESS : self::RES_SOME_ERRORS);

        return $result['values'];
    }

    #[\Override]
    protected function doFlush(int $delay): bool
    {
        // Reject unsupported features *before* spending I/O on draining the
        // write buffer — there's no point flushing pending writes only to
        // bail out with RES_NOT_SUPPORTED a line later.
        if ($delay > 0) {
            $this->setResult(self::RES_NOT_SUPPORTED, 'flush delay not supported on Redis');

            return false;
        }

        $this->flushWriteBuffer();
        if (!$this->ensureServersAvailable()) {
            return false;
        }

        $result = $this->collectFromServers(function (int $i): bool {
            $this->redisForServerIndex($i)->flushdb();

            return true;
        }, false);

        $this->setResult($result['allOk'] ? self::RES_SUCCESS : self::RES_FAILURE);

        return $result['allOk'];
    }

    /**
     * @return list<string>|false
     */
    #[\Override]
    protected function doGetAllKeys(): array|false
    {
        $this->flushWriteBuffer();
        if (!$this->ensureServersAvailable()) {
            return false;
        }

        $prefixLen = \strlen(self::ITEM_KEY_PREFIX);
        $result = $this->collectFromServers(function (int $i) use ($prefixLen): array {
            $r = $this->redisForServerIndex($i);
            $cursor = 0;
            $keys = [];
            do {
                $scan = $r->scan($cursor, ['MATCH' => self::ITEM_KEY_PREFIX.'*', 'COUNT' => 500]);
                $cursor = $scan[0];
                foreach ($scan[1] as $k) {
                    if (str_starts_with($k, self::ITEM_KEY_PREFIX)) {
                        $keys[] = substr($k, $prefixLen);
                    }
                }
            } while (0 !== $cursor);

            return $keys;
        }, []);

        if (!$result['allOk'] && !$result['anyOk']) {
            $this->setResult(self::RES_FAILURE);

            return false;
        }

        $this->setResult($result['allOk'] ? self::RES_SUCCESS : self::RES_SOME_ERRORS);

        // Single allocation: O(N) instead of the array_merge-in-loop O(N*S)
        // and array_unique's string-key O(N log N) — array_flip dedupes via
        // hash table, and array_keys hands us back a list<string>.
        $merged = [] === $result['values'] ? [] : array_merge(...array_values($result['values']));

        return array_keys(array_flip($merged));
    }

    // -----------------------------------------------------------------------
    // Redis-specific internals
    // -----------------------------------------------------------------------

    private function readEntry(string $pk, int $serverIndex): ?CacheEntry
    {
        $rk = $this->itemRedisKey($pk);
        $h = $this->redisForServerIndex($serverIndex)->hgetall($rk);

        return $this->cacheEntryFromHash($h);
    }

    /**
     * Pipelined HGETALL: one TCP write + N replies per Redis backend, instead of N round-trips.
     *
     * @param list<string> $prefixedKeys
     *
     * @return list<CacheEntry|null>
     */
    private function readEntriesPipelined(array $prefixedKeys, int $serverIndex): array
    {
        if ([] === $prefixedKeys) {
            return [];
        }

        $r = $this->redisForServerIndex($serverIndex);
        $commands = [];
        foreach ($prefixedKeys as $pk) {
            $commands[] = ['HGETALL', $this->itemRedisKey($pk)];
        }

        $replies = $r->pipeline($commands);
        $out = [];
        foreach ($replies as $reply) {
            if ($reply instanceof \Throwable) {
                throw $reply;
            }

            $out[] = $this->cacheEntryFromHash($this->kvArrayToMap(\is_array($reply) ? $reply : []));
        }

        return $out;
    }

    /**
     * @param array<int|string, mixed> $raw raw RESP `*` reply with alternating field/value entries
     *
     * @return array<string, string>
     */
    private function kvArrayToMap(array $raw): array
    {
        $out = [];
        $values = array_values($raw);
        $n = \count($values);
        for ($i = 0; $i + 1 < $n; $i += 2) {
            $k = $values[$i];
            $v = $values[$i + 1];
            if (\is_string($k) && \is_string($v)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $h
     */
    private function cacheEntryFromHash(array $h): ?CacheEntry
    {
        if (!isset($h['d'], $h['f'], $h['c']) || !is_numeric($h['f'])) {
            return null;
        }

        $flagsInt = (int) $h['f'];
        try {
            $value = ValueCodec::decode(
                $h['d'],
                $flagsInt,
                $this->optionInt(self::OPT_SERIALIZER, self::SERIALIZER_PHP),
                $this->optionBool(self::OPT_ALLOW_SERIALIZED_CLASSES, false),
                $this->encodingContext(),
            );
        } catch (\Throwable) {
            $this->setResult(self::RES_PAYLOAD_FAILURE);

            return null;
        }

        return new CacheEntry($value, $this->casValue($h['c']), ValueCodec::getUserFlags($flagsInt));
    }

    private function itemRedisKey(string $pk): string
    {
        [$encodedPk] = KeyFormatter::encodeMetaKey($pk);

        return self::ITEM_KEY_PREFIX.$encodedPk;
    }

    private function redisForServerIndex(int $serverIndex): NativeRedisClient
    {
        $st = $this->st();
        if (isset($st->redisByServerIndex[$serverIndex])) {
            return $st->redisByServerIndex[$serverIndex];
        }

        $servers = $st->selector->getServers();
        if ([] === $servers) {
            throw new \RuntimeException('no servers');
        }

        $server = $servers[$serverIndex] ?? null;
        if (null === $server) {
            throw new \RuntimeException('invalid server index');
        }

        $redis = new NativeRedisClient(
            $server['host'],
            $server['port'],
            $this->recvSendTimeoutSeconds(),
            $server['user'] ?? null,
            $server['password'] ?? null,
            $server['database'] ?? null,
        );
        $redis->connect();
        $st->redisByServerIndex[$serverIndex] = $redis;

        return $redis;
    }

    private function disconnectRedis(): void
    {
        $st = $this->st();

        // Best-effort: hand the buffered writes to their cached connections
        // before we tear them down. The connection cache is keyed by the
        // *original* server index, so even a pool reshuffle (resetServerList,
        // setBucket) cannot reroute these writes to a different physical
        // server — they either land on the original socket or fail outright.
        // Either way, we drop the buffer afterwards so a partial failure here
        // doesn't double-fire writes on the next operation.
        try {
            $this->flushWriteBuffer();
        } catch (\Throwable) {
        }

        $this->writeBuffer = [];

        foreach ($st->redisByServerIndex as $idx => $redis) {
            try {
                $redis->disconnect();
            } catch (\Throwable) {
            }

            unset($st->redisByServerIndex[$idx]);
        }
    }

    private function flushWriteBuffer(): void
    {
        if ([] === $this->writeBuffer) {
            return;
        }

        $buf = $this->writeBuffer;
        $this->writeBuffer = [];
        foreach ($buf as $item) {
            $item['fn']($this->redisForServerIndex($item['serverIndex']));
        }
    }

    /**
     * @param callable(NativeRedisClient):void $fn
     */
    private function enqueueWrite(callable $fn, int $serverIndex): void
    {
        if ($this->optionBool(self::OPT_BUFFER_WRITES, false)) {
            $this->writeBuffer[] = ['serverIndex' => $serverIndex, 'fn' => $fn];

            return;
        }

        $fn($this->redisForServerIndex($serverIndex));
    }

    /**
     * Honours memcached's "expiration > 30 days = absolute Unix timestamp"
     * convention; otherwise applications passing {@code time() + 60} as a TTL
     * would silently get an EXPIRE of "~57 years from now" on Redis.
     */
    private function ttlSeconds(int $expiration): ?int
    {
        return Expiration::toRelativeSeconds($expiration);
    }

    /**
     * Server-side append/prepend. The {@code OPT_COMPRESSION = false} guard
     * upstream means the existing value is stored as a raw byte string, which
     * lets us perform the concatenation inside Lua and bump CAS atomically.
     */
    private function storeAppendOrPrepend(string $pk, mixed $value, StoreMode $mode, ?string $serverKey, string $key): bool
    {
        if (!\is_string($value)) {
            $this->setResult(self::RES_NOTSTORED);

            return false;
        }

        $limit = $this->optionInt(self::OPT_ITEM_SIZE_LIMIT, 0);
        if ($limit > 0 && \strlen($value) > $limit) {
            $this->setResult(self::RES_E2BIG);

            return false;
        }

        $idx = $this->pickServerIndex($serverKey, $key);
        if (!$this->shouldBufferNoReplyWrite()) {
            $this->flushWriteBuffer();
        }

        $rk = $this->itemRedisKey($pk);
        $modeToken = $mode->value;
        $fn = static function (NativeRedisClient $r) use ($rk, $value, $modeToken): void {
            $reply = $r->evalScript(RedisItemScripts::LUA_APPEND_PREPEND, [$rk], [$value, $modeToken]);
            [$status] = RedisItemScripts::decodePairReply($reply);
            if (RedisItemScripts::STATUS_OK !== $status) {
                throw new RedisClientStoreException(RedisItemScripts::STATUS_NOT_STORED);
            }
        };

        try {
            return $this->runStoreFn($fn, $idx);
        } catch (\Throwable $throwable) {
            $this->recordServerFailure($idx, $throwable);
            $this->setResult(self::RES_FAILURE, $throwable->getMessage());

            return false;
        }
    }

    /**
     * @return callable(NativeRedisClient):void
     */
    private function makeCasSetClosure(string $rk, string $payload, int $flags, ?int $ttl, string $expectedCas): callable
    {
        return static function (NativeRedisClient $r) use ($rk, $payload, $flags, $ttl, $expectedCas): void {
            $reply = $r->evalScript(RedisItemScripts::LUA_CAS_SET, [$rk], [
                $payload,
                (string) $flags,
                (string) ($ttl ?? 0),
                $expectedCas,
            ]);
            [$status] = RedisItemScripts::decodePairReply($reply);
            if (RedisItemScripts::STATUS_OK === $status) {
                return;
            }

            if (RedisItemScripts::STATUS_NOT_FOUND === $status) {
                throw new RedisClientStoreException(RedisItemScripts::STATUS_NOT_FOUND);
            }

            if (RedisItemScripts::STATUS_DATA_EXISTS === $status) {
                throw new RedisClientStoreException(RedisItemScripts::STATUS_DATA_EXISTS);
            }

            throw new RedisClientStoreException(RedisItemScripts::STATUS_NOT_STORED);
        };
    }

    /**
     * @return callable(NativeRedisClient):void
     */
    private function makeAddClosure(string $rk, string $payload, int $flags, ?int $ttl): callable
    {
        return static function (NativeRedisClient $r) use ($rk, $payload, $flags, $ttl): void {
            $reply = $r->evalScript(RedisItemScripts::LUA_ADD, [$rk], [
                $payload,
                (string) $flags,
                (string) ($ttl ?? 0),
            ]);
            [$status] = RedisItemScripts::decodePairReply($reply);
            if (RedisItemScripts::STATUS_OK !== $status) {
                throw new RedisClientStoreException(RedisItemScripts::STATUS_NOT_STORED);
            }
        };
    }

    /**
     * @return callable(NativeRedisClient):void
     */
    private function makeReplaceClosure(string $rk, string $payload, int $flags, ?int $ttl): callable
    {
        return static function (NativeRedisClient $r) use ($rk, $payload, $flags, $ttl): void {
            $reply = $r->evalScript(RedisItemScripts::LUA_REPLACE, [$rk], [
                $payload,
                (string) $flags,
                (string) ($ttl ?? 0),
            ]);
            [$status] = RedisItemScripts::decodePairReply($reply);
            if (RedisItemScripts::STATUS_OK !== $status) {
                throw new RedisClientStoreException(RedisItemScripts::STATUS_NOT_STORED);
            }
        };
    }

    /**
     * @param callable(NativeRedisClient):void $fn
     */
    private function runStoreFn(callable $fn, int $serverIndex): bool
    {
        try {
            // enqueueWrite() already honours OPT_BUFFER_WRITES, so the only
            // backend-specific knob left here is whether a "no-reply" write
            // should defer the flush (memcached compatibility: fire-and-forget
            // writes are allowed to accumulate until the next round-trip).
            $this->enqueueWrite($fn, $serverIndex);
            if (!$this->useNoReply()) {
                $this->flushWriteBuffer();
            }

            return $this->okResult(self::RES_SUCCESS);
        } catch (RedisClientStoreException $redisClientStoreException) {
            return match ($redisClientStoreException->outcome) {
                RedisItemScripts::STATUS_NOT_STORED => $this->failResult(self::RES_NOTSTORED),
                RedisItemScripts::STATUS_NOT_FOUND => $this->failResult(self::RES_NOTFOUND),
                RedisItemScripts::STATUS_DATA_EXISTS => $this->failResult(self::RES_DATA_EXISTS),
                default => throw $redisClientStoreException,
            };
        }
    }

    /**
     * @return array<string, string>
     */
    private function redisInfoAssoc(NativeRedisClient $redis, ?string $section = null): array
    {
        $reply = (null === $section || '' === $section) ? $redis->info() : $redis->info($section);

        return RedisInfoReplyFlatten::toStringMap($reply);
    }
}
