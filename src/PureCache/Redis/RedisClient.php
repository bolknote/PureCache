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

    #[\Override]
    public function isUnsupportedOption(int $option): bool
    {
        return false;
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
        });
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
        $this->pristine = false;
        if ($mode->isConcatenation() && $this->optionBool(self::OPT_COMPRESSION, true)) {
            trigger_error('cannot append/prepend with compression turned on', \E_USER_WARNING);
            $this->setResult(self::RES_NOTSTORED);

            return false;
        }

        if ($mode->isConcatenation() && null !== $this->encodingContext()) {
            trigger_error('cannot append/prepend with encoding key set', \E_USER_WARNING);
            $this->setResult(self::RES_NOTSTORED);

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

        try {
            $st = $this->st();
            [$payload, $flags] = ValueCodec::encode(
                $value,
                $this->optionInt(self::OPT_SERIALIZER, self::SERIALIZER_PHP),
                $this->optionBool(self::OPT_COMPRESSION, true),
                $this->optionInt(self::OPT_COMPRESSION_TYPE, self::COMPRESSION_TYPE_FASTLZ),
                $this->optionInt(self::OPT_COMPRESSION_LEVEL, 3),
                $st->compressionThreshold,
                $st->compressionFactor,
                $this->optionInt(self::OPT_USER_FLAGS, -1),
                $this->encodingContext(),
            );
        } catch (\Throwable) {
            $this->setResult(self::RES_PAYLOAD_FAILURE);

            return false;
        }

        $limit = $this->optionInt(self::OPT_ITEM_SIZE_LIMIT, 0);
        if ($limit > 0 && \strlen($payload) > $limit) {
            $this->setResult(self::RES_E2BIG);

            return false;
        }

        $idx = $this->pickServerIndex($serverKey, $key);

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

        try {
            return $this->runStoreFn($fn, $idx);
        } catch (\Throwable $throwable) {
            $this->recordServerFailure($idx, $throwable);
            $this->setResult(self::RES_FAILURE, $throwable->getMessage());

            return false;
        }
    }

    /**
     * Pipelined {@code setMulti} via EVALSHA(LUA_CAS_SET) per-server: one TCP
     * write that batches all keys for the same Redis backend, then a single
     * receive loop drains the replies. Falls back to the single-shot
     * {@code doStore} path for any item whose value fails encoding (bad
     * compression input, oversized payload, etc.) so PECL's RES_SOME_ERRORS
     * semantics remain intact.
     *
     * @param array<mixed> $items
     */
    #[\Override]
    protected function doStoreMulti(array $items, int $expiration, ?string $serverKey): bool
    {
        $this->pristine = false;
        if ([] === $items) {
            $this->setResult(self::RES_SUCCESS);

            return true;
        }

        $ok = true;
        $st = $this->st();

        /** @var array<int, list<array{cmd: list<string>, key: string}>> $byServer */
        $byServer = [];
        foreach ($items as $key => $value) {
            $keyString = (string) $key;
            $pk = $this->prefixedKey($keyString);
            if (!$this->checkKeyInternal($pk)) {
                $this->setResult(self::RES_BAD_KEY_PROVIDED);
                $ok = false;
                continue;
            }

            try {
                [$payload, $flags] = ValueCodec::encode(
                    $value,
                    $this->optionInt(self::OPT_SERIALIZER, self::SERIALIZER_PHP),
                    $this->optionBool(self::OPT_COMPRESSION, true),
                    $this->optionInt(self::OPT_COMPRESSION_TYPE, self::COMPRESSION_TYPE_FASTLZ),
                    $this->optionInt(self::OPT_COMPRESSION_LEVEL, 3),
                    $st->compressionThreshold,
                    $st->compressionFactor,
                    $this->optionInt(self::OPT_USER_FLAGS, -1),
                    $this->encodingContext(),
                );
            } catch (\Throwable) {
                $this->setResult(self::RES_PAYLOAD_FAILURE);
                $ok = false;
                continue;
            }

            $limit = $this->optionInt(self::OPT_ITEM_SIZE_LIMIT, 0);
            if ($limit > 0 && \strlen($payload) > $limit) {
                $this->setResult(self::RES_E2BIG);
                $ok = false;
                continue;
            }

            $idx = $this->pickServerIndex($serverKey, $keyString);
            $rk = $this->itemRedisKey($pk);
            $ttl = $this->ttlSeconds($expiration);

            $cmd = ['EVALSHA', sha1(RedisItemScripts::LUA_CAS_SET), '1', $rk, $payload, (string) $flags, (string) ($ttl ?? 0), ''];
            $byServer[$idx][] = ['cmd' => $cmd, 'key' => $keyString];
        }

        foreach ($byServer as $idx => $batch) {
            try {
                $redis = $this->redisForServerIndex($idx);
                $commands = array_map(static fn (array $b): array => $b['cmd'], $batch);
                $replies = $this->pipelineWithScriptFallback($redis, $commands, RedisItemScripts::LUA_CAS_SET);

                foreach ($replies as $reply) {
                    if ($reply instanceof \Throwable) {
                        $this->setResult(self::RES_FAILURE, $reply->getMessage());
                        $ok = false;
                        continue;
                    }

                    try {
                        [$status] = RedisItemScripts::decodePairReply($reply);
                    } catch (\Throwable $throwable) {
                        $this->setResult(self::RES_FAILURE, $throwable->getMessage());
                        $ok = false;
                        continue;
                    }

                    if (RedisItemScripts::STATUS_OK !== $status) {
                        $ok = false;
                    }
                }
            } catch (\Throwable $throwable) {
                $this->recordServerFailure($idx, $throwable);
                $this->setResult(self::RES_FAILURE, $throwable->getMessage());
                $ok = false;
            }
        }

        $this->setResult($ok ? self::RES_SUCCESS : self::RES_SOME_ERRORS);

        return $ok;
    }

    /**
     * Wraps {@see NativeRedisClient::pipeline()} so a NOSCRIPT failure on the
     * first reply triggers a one-shot {@code SCRIPT LOAD} + retry of the whole
     * batch — same lazy-cache strategy as {@see NativeRedisClient::evalScript()}
     * but pipeline-aware.
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
        $needsLoad = false;
        foreach ($replies as $reply) {
            if ($reply instanceof RedisCommandException && str_starts_with($reply->getMessage(), 'NOSCRIPT')) {
                $needsLoad = true;
                break;
            }
        }

        if (!$needsLoad) {
            return $replies;
        }

        $redis->executeRaw(['SCRIPT', 'LOAD', $script]);

        return $redis->pipeline($commands);
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
            } catch (RedisClientStoreException $redisClientStoreException) {
                if (RedisItemScripts::STATUS_NOT_FOUND === $redisClientStoreException->outcome) {
                    $this->setResult(self::RES_NOTFOUND);

                    return false;
                }

                throw $redisClientStoreException;
            }

            $this->setResult(self::RES_SUCCESS);

            return true;
        });
    }

    #[\Override]
    protected function doDelete(string $key, ?string $serverKey, int $time): bool
    {
        // Match the Memcached backend's validation order: bad key wins over
        // delete-time, which in turn wins over an empty server pool.
        $this->pristine = false;
        $pk = $this->prefixedKey($key);
        if (!$this->checkKeyInternal($pk) || (null !== $serverKey && !$this->checkKeyInternal($serverKey))) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if (!$this->acceptDeleteTime($time)) {
            return false;
        }

        if (!$this->ensureServersAvailable()) {
            return false;
        }

        if (!$this->shouldBufferNoReplyWrite()) {
            $this->flushWriteBuffer();
        }

        $idx = $this->pickServerIndex($serverKey, $key);
        try {
            $rk = $this->itemRedisKey($pk);
            $fn = static function (NativeRedisClient $r) use ($rk): void {
                $r->del([$rk]);
            };

            if ($this->useNoReply()) {
                $this->enqueueWrite($fn, $idx);
                $this->setResult(self::RES_SUCCESS);

                return true;
            }

            $n = 0;
            $this->enqueueWrite(static function (NativeRedisClient $r) use ($rk, &$n): void {
                $n = $r->del([$rk]);
            }, $idx);
            $this->flushWriteBuffer();

            if (0 === $n) {
                $this->setResult(self::RES_NOTFOUND);

                return false;
            }

            $this->setResult(self::RES_SUCCESS);

            return true;
        } catch (\Throwable $throwable) {
            $this->recordServerFailure($idx, $throwable);
            $this->setResult(self::RES_FAILURE, $throwable->getMessage());

            return false;
        }
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
        $st = $this->st();
        if ([] === $st->selector->getServers()) {
            $this->setResult(self::RES_NO_SERVERS);

            return false;
        }

        $out = [];
        $ok = true;
        foreach ($st->selector->getServers() as $i => $s) {
            $label = $s['host'].':'.$s['port'];
            try {
                $r = $this->redisForServerIndex($i);
                if (null === $type || '' === $type) {
                    $parsed = RedisStatsAsMemcached::general($r);
                } elseif ('items' === $type) {
                    $parsed = RedisStatsAsMemcached::items($r, self::ITEM_KEY_PREFIX.'*');
                } elseif ('slabs' === $type) {
                    $scan = RedisStatsAsMemcached::scanCountAndFirstKey($r, self::ITEM_KEY_PREFIX.'*', 100_000);
                    $parsed = RedisStatsAsMemcached::slabs($r, $scan['count']);
                } elseif ('sizes' === $type) {
                    $parsed = RedisStatsAsMemcached::sizes();
                } else {
                    $parsed = $this->redisInfoAssoc($r, $type);
                    if (!isset($parsed['version'])) {
                        $parsed['version'] = $parsed['redis_version'] ?? ($parsed['Version'] ?? 'unknown');
                    }
                }

                $out[$label] = $parsed;
            } catch (\Throwable $exception) {
                $this->recordServerFailure($i, $exception);
                $ok = false;
                $out[$label] = false;
            }
        }

        $this->setResult($ok ? self::RES_SUCCESS : self::RES_SOME_ERRORS);

        return $out;
    }

    /**
     * @return array<string, string>|false
     */
    #[\Override]
    protected function doGetVersion(): array|false
    {
        $this->flushWriteBuffer();
        $st = $this->st();
        if ([] === $st->selector->getServers()) {
            $this->setResult(self::RES_NO_SERVERS);

            return false;
        }

        $out = [];
        $ok = true;
        foreach ($st->selector->getServers() as $i => $s) {
            $label = $s['host'].':'.$s['port'];
            try {
                $r = $this->redisForServerIndex($i);
                $info = $this->redisInfoAssoc($r, 'server');
                $out[$label] = $info['redis_version'] ?? '';
            } catch (\Throwable $exception) {
                $this->recordServerFailure($i, $exception);
                $ok = false;
                $out[$label] = '';
            }
        }

        $this->setResult($ok ? self::RES_SUCCESS : self::RES_SOME_ERRORS);

        return $out;
    }

    #[\Override]
    protected function doFlush(int $delay): bool
    {
        $this->flushWriteBuffer();
        $st = $this->st();
        if ([] === $st->selector->getServers()) {
            $this->setResult(self::RES_NO_SERVERS);

            return false;
        }

        if ($delay > 0) {
            $this->setResult(self::RES_NOT_SUPPORTED, 'flush delay not supported on Redis');

            return false;
        }

        $ok = true;
        foreach (array_keys($st->selector->getServers()) as $i) {
            try {
                $this->redisForServerIndex($i)->flushdb();
            } catch (\Throwable $exception) {
                $this->recordServerFailure($i, $exception);
                $ok = false;
            }
        }

        $this->setResult($ok ? self::RES_SUCCESS : self::RES_FAILURE);

        return $ok;
    }

    /**
     * @return list<string>|false
     */
    #[\Override]
    protected function doGetAllKeys(): array|false
    {
        $this->flushWriteBuffer();
        $st = $this->st();
        if ([] === $st->selector->getServers()) {
            $this->setResult(self::RES_NO_SERVERS);

            return false;
        }

        $keys = [];
        $ok = true;
        $hadSuccess = false;
        foreach (array_keys($st->selector->getServers()) as $i) {
            try {
                $r = $this->redisForServerIndex($i);
                $cursor = 0;
                do {
                    $scan = $r->scan($cursor, ['MATCH' => self::ITEM_KEY_PREFIX.'*', 'COUNT' => 500]);
                    $cursor = $scan[0];
                    foreach ($scan[1] as $k) {
                        if (str_starts_with($k, self::ITEM_KEY_PREFIX)) {
                            $keys[] = substr($k, \strlen(self::ITEM_KEY_PREFIX));
                        }
                    }
                } while (0 !== $cursor);

                $hadSuccess = true;
            } catch (\Throwable $exception) {
                $this->recordServerFailure($i, $exception);
                $ok = false;
            }
        }

        if (!$ok && !$hadSuccess) {
            $this->setResult(self::RES_FAILURE);

            return false;
        }

        $this->setResult($ok ? self::RES_SUCCESS : self::RES_SOME_ERRORS);

        return array_values(array_unique($keys));
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
            $this->redisReadWriteTimeout(),
            $server['user'] ?? null,
            $server['password'] ?? null,
            $server['database'] ?? null,
        );
        $redis->connect();
        $st->redisByServerIndex[$serverIndex] = $redis;

        return $redis;
    }

    private function redisReadWriteTimeout(): float
    {
        $recv = $this->optionInt(self::OPT_RECV_TIMEOUT, 0);
        $send = $this->optionInt(self::OPT_SEND_TIMEOUT, 0);
        $ms = max($recv, $send);

        return $ms > 0 ? $ms / 1000.0 : 0.0;
    }

    private function disconnectRedis(): void
    {
        $st = $this->st();
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
            if ($this->useNoReply()) {
                if ($this->optionBool(self::OPT_BUFFER_WRITES, false)) {
                    $this->enqueueWrite($fn, $serverIndex);
                } else {
                    $fn($this->redisForServerIndex($serverIndex));
                }

                $this->setResult(self::RES_SUCCESS);

                return true;
            }

            $this->enqueueWrite($fn, $serverIndex);
            $this->flushWriteBuffer();
            $this->setResult(self::RES_SUCCESS);

            return true;
        } catch (RedisClientStoreException $redisClientStoreException) {
            return match ($redisClientStoreException->outcome) {
                RedisItemScripts::STATUS_NOT_STORED => $this->failResult(self::RES_NOTSTORED),
                RedisItemScripts::STATUS_NOT_FOUND => $this->failResult(self::RES_NOTFOUND),
                RedisItemScripts::STATUS_DATA_EXISTS => $this->failResult(self::RES_DATA_EXISTS),
                default => throw $redisClientStoreException,
            };
        }
    }

    private function failResult(int $code): bool
    {
        $this->setResult($code);

        return false;
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
