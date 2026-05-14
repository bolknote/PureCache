<?php

declare(strict_types=1);

namespace PureCache\Redis;

use PureCache\AbstractCacheClient;
use PureCache\Internal\ClientCoreState;
use PureCache\Internal\KeyFormatter;
use PureCache\Internal\ValueCodec;
use PureCache\MemcachedConstants;
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
    public const string ITEM_KEY_PREFIX = 'pm:v1:';

    /** @var array<string, RedisClientState> */
    private static array $persistentPool = [];

    /** @var list<array{serverIndex:int, fn:callable(NativeRedisClient):void}> */
    private array $writeBuffer = [];

    #[\Override]
    protected function createState(?string $persistentId): ClientCoreState
    {
        return RedisClientState::createFresh($persistentId);
    }

    #[\Override]
    protected function lookupPersistentState(string $persistentId): ?ClientCoreState
    {
        return self::$persistentPool[$persistentId] ?? null;
    }

    #[\Override]
    protected function registerPersistentState(string $persistentId, ClientCoreState $state): void
    {
        self::$persistentPool[$persistentId] = $state;
    }

    #[\Override]
    protected function defaultPort(): int
    {
        return 6379;
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
        $st = $this->st();
        $idx = null !== $serverKey
            ? $st->selector->pickServerIndex($serverKey)
            : $st->selector->pickServerIndex($this->routingKey($key));

        try {
            $entry = $this->readEntry($prefixedKey, $idx);
            if (null === $entry) {
                $this->setResult(self::RES_NOTFOUND);

                return false;
            }

            $this->setResult(self::RES_SUCCESS);

            return $this->valueForGetFlags($entry, $getFlags);
        } catch (\Throwable $throwable) {
            $this->recordServerFailure($idx, $throwable);
            $this->setResult(self::RES_FAILURE, $throwable->getMessage());

            return false;
        }
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
                $entries = $this->readEntriesPipelined(array_map(static fn (array $p) => $p[1], $pairs), $idx);
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
     * @return list<array<string, mixed>>|null
     */
    #[\Override]
    protected function doFetchBatch(array $keys, ?string $serverKey, bool $withCas): ?array
    {
        try {
            $results = [];
            $byServer = $this->groupKeysByServer($keys, $serverKey);
            foreach ($byServer as $idx => $pairs) {
                $entries = $this->readEntriesPipelined(array_map(static fn (array $p) => $p[1], $pairs), $idx);
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

            return null;
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
                $entries = $this->readEntriesPipelined(array_map(static fn (array $p) => $p[1], $pairs), $idx);
                foreach ($pairs as $i => [$orig]) {
                    $entry = $entries[$i] ?? null;
                    if (null === $entry) {
                        continue;
                    }

                    $cb = ['key' => $orig, 'value' => $this->valueFromEntry($entry)];
                    if ($withCas) {
                        $cb['cas'] = $this->casValue($entry['c']);
                        $cb['flags'] = ValueCodec::getUserFlags($entry['f']);
                    }

                    $valueCb($this, $cb);
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
    protected function doStore(string $key, mixed $value, int $expiration, string $mode, ?string $serverKey, ?string $casToken): bool
    {
        $this->pristine = false;
        if (('A' === $mode || 'P' === $mode) && $this->optionBool(self::OPT_COMPRESSION, true)) {
            trigger_error('cannot append/prepend with compression turned on', \E_USER_WARNING);
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

        if ('A' === $mode || 'P' === $mode) {
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

        $idx = $this->pickIndex($serverKey, $key);

        if (!$this->shouldBufferNoReplyWrite()) {
            $this->flushWriteBuffer();
        }

        $rk = $this->itemRedisKey($pk);
        $ttl = $this->ttlSeconds($expiration);

        $fn = match ($mode) {
            'E' => $this->makeAddClosure($rk, $payload, $flags, $ttl),
            'R' => $this->makeReplaceClosure($rk, $payload, $flags, $ttl),
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
        foreach ($items as $key => $value) {
            $keyString = (string) $key;
            if (!$this->doStore($keyString, $value, $expiration, 'S', $serverKey, null)) {
                $ok = false;
            }
        }

        $this->setResult($ok ? self::RES_SUCCESS : self::RES_SOME_ERRORS);

        return $ok;
    }

    #[\Override]
    protected function doTouch(string $key, int $expiration, ?string $serverKey): bool
    {
        $this->pristine = false;
        if (!$this->shouldBufferNoReplyWrite()) {
            $this->flushWriteBuffer();
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

        $idx = $this->pickIndex($serverKey, $key);
        try {
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
            } catch (RedisClientStoreException $exception) {
                if (RedisItemScripts::STATUS_NOT_FOUND === $exception->outcome) {
                    $this->setResult(self::RES_NOTFOUND);

                    return false;
                }

                throw $exception;
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
    protected function doDelete(string $key, ?string $serverKey, int $time): bool
    {
        $this->pristine = false;
        if (!$this->shouldBufferNoReplyWrite()) {
            $this->flushWriteBuffer();
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

        if (!$this->acceptDeleteTime($time)) {
            return false;
        }

        if (!$this->ensureServersAvailable()) {
            return false;
        }

        $idx = $this->pickIndex($serverKey, $key);
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
    protected function doArith(string $key, int $offset, bool $decrement, ?string $serverKey, int $initialValue, int $expiry): int|false
    {
        if ($offset < 0) {
            trigger_error('offset cannot be a negative value', \E_USER_WARNING);
            $this->setResult(self::RES_INVALID_ARGUMENTS);

            return false;
        }

        $this->pristine = false;
        $this->flushWriteBuffer();

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

        $idx = $this->pickIndex($serverKey, $key);
        $rk = $this->itemRedisKey($pk);

        try {
            $reply = $this->redisForServerIndex($idx)->evalScript(
                RedisItemScripts::LUA_ARITH,
                [$rk],
                [(string) $offset, $decrement ? 'D' : 'I'],
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
        } catch (\Throwable $throwable) {
            $this->recordServerFailure($idx, $throwable);
            $this->setResult(self::RES_FAILURE, $throwable->getMessage());

            return false;
        }
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

    /**
     * @param list<string> $keys
     *
     * @return array<int, list<array{0:string,1:string}>>
     */
    private function groupKeysByServer(array $keys, ?string $serverKey): array
    {
        $st = $this->st();
        $by = [];
        if (null === $serverKey) {
            foreach ($keys as $ks) {
                $pk = $this->prefixedKey($ks);
                $idx = $st->selector->pickServerIndex($this->routingKey($ks));
                $by[$idx][] = [$ks, $pk];
            }
        } else {
            $idx = $st->selector->pickServerIndex($serverKey);
            foreach ($keys as $ks) {
                $by[$idx][] = [$ks, $this->prefixedKey($ks)];
            }
        }

        return $by;
    }

    /**
     * @return array{d:string,f:int,c:string}|null
     */
    private function readEntry(string $pk, int $serverIndex): ?array
    {
        $rk = $this->itemRedisKey($pk);
        $h = $this->redisForServerIndex($serverIndex)->hgetall($rk);

        return $this->normalizeEntry($h);
    }

    /**
     * Pipelined HGETALL: one TCP write + N replies per Redis backend, instead of N round-trips.
     *
     * @param list<string> $prefixedKeys
     *
     * @return list<array{d:string,f:int,c:string}|null>
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

            $out[] = $this->normalizeEntry($this->kvArrayToMap(\is_array($reply) ? $reply : []));
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
     *
     * @return array{d:string,f:int,c:string}|null
     */
    private function normalizeEntry(array $h): ?array
    {
        if (!isset($h['d'], $h['f'], $h['c'])) {
            return null;
        }

        if (!is_numeric($h['f'])) {
            return null;
        }

        return ['d' => $h['d'], 'f' => (int) $h['f'], 'c' => $h['c']];
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

    private function pickIndex(?string $serverKey, string $key): int
    {
        $st = $this->st();

        return null !== $serverKey
            ? $st->selector->pickServerIndex($serverKey)
            : $st->selector->pickServerIndex($this->routingKey($key));
    }

    private function ttlSeconds(int $expiration): ?int
    {
        if ($expiration <= 0) {
            return null;
        }

        return $expiration;
    }

    /**
     * @param array{d:string,f:int,c:string} $entry
     */
    private function valueFromEntry(array $entry): mixed
    {
        return ValueCodec::decode($entry['d'], $entry['f'], $this->optionInt(self::OPT_SERIALIZER, self::SERIALIZER_PHP));
    }

    /**
     * @param array{d:string,f:int,c:string} $entry
     */
    private function valueForGetFlags(array $entry, int $getFlags): mixed
    {
        $value = $this->valueFromEntry($entry);
        if (($getFlags & MemcachedConstants::GET_EXTENDED) !== 0) {
            return [
                'value' => $value,
                'cas' => $this->casValue($entry['c']),
                'flags' => ValueCodec::getUserFlags($entry['f']),
            ];
        }

        return $value;
    }

    /**
     * @param array{d:string,f:int,c:string} $entry
     *
     * @return array<string, mixed>
     */
    private function delayedEntry(string $key, array $entry, bool $withCas): array
    {
        $row = ['key' => $key, 'value' => $this->valueFromEntry($entry)];
        if ($withCas) {
            $row['cas'] = $this->casValue($entry['c']);
            $row['flags'] = ValueCodec::getUserFlags($entry['f']);
        }

        return $row;
    }

    /**
     * Server-side append/prepend. The {@code OPT_COMPRESSION = false} guard
     * upstream means the existing value is stored as a raw byte string, which
     * lets us perform the concatenation inside Lua and bump CAS atomically.
     */
    private function storeAppendOrPrepend(string $pk, mixed $value, string $mode, ?string $serverKey, string $key): bool
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

        $idx = $this->pickIndex($serverKey, $key);
        if (!$this->shouldBufferNoReplyWrite()) {
            $this->flushWriteBuffer();
        }

        $rk = $this->itemRedisKey($pk);
        $fn = static function (NativeRedisClient $r) use ($rk, $value, $mode): void {
            $reply = $r->evalScript(RedisItemScripts::LUA_APPEND_PREPEND, [$rk], [$value, $mode]);
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
        } catch (RedisClientStoreException $exception) {
            return match ($exception->outcome) {
                RedisItemScripts::STATUS_NOT_STORED => $this->failResult(self::RES_NOTSTORED),
                RedisItemScripts::STATUS_NOT_FOUND => $this->failResult(self::RES_NOTFOUND),
                RedisItemScripts::STATUS_DATA_EXISTS => $this->failResult(self::RES_DATA_EXISTS),
                default => throw $exception,
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
