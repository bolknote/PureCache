<?php

declare(strict_types=1);

namespace PureCache\Ignite;

use PureCache\AbstractCacheClient;
use PureCache\Ignite\Internal\IgniteCacheCodec;
use PureCache\Internal\CacheEntry;
use PureCache\Internal\PersistentStateRegistry;
use PureCache\Internal\StoreMode;
use PureCache\Internal\ValueCodec;

/**
 * PECL {@code \Memcached}-shaped client backed by Apache Ignite via the native
 * thin-client binary protocol (port 10800 by default).
 *
 * Each PureCache entry is stored as a single {@code byte[]} in a shared cache.
 * The byte array carries an in-band 16-byte header — CAS, F-flags, payload
 * length — that lets us preserve memcached semantics on top of an engine that
 * has no built-in CAS counter:
 *  - `set`/`add`/`replace`/`append`/`prepend`/`cas`/`incr`/`decr` rotate the
 *    CAS field every time they write.
 *  - `cas($token, …)` compares against that header and uses Ignite's atomic
 *    {@code REPLACE_IF_EQUALS} to commit, so the check + write is one server
 *    round-trip without any leaked race window.
 *  - `append`/`prepend` and `increment`/`decrement` use the same operator in
 *    a short optimistic retry loop because Ignite has no scriptable atomic
 *    arithmetic equivalent to the Redis Lua scripts.
 *
 * The {@link ServerSelector} treats each {@code addServer()} endpoint as an
 * independent shard (mirroring the memcached/Redis backends). For real Ignite
 * clusters you typically add one endpoint and let the cluster itself handle
 * partitioning.
 *
 * @extends AbstractCacheClient<IgniteClientState>
 */
final class IgniteClient extends AbstractCacheClient
{
    /** @use PersistentStateRegistry<IgniteClientState> */
    use PersistentStateRegistry;

    public const string CACHE_NAME = 'PURECACHE_V1';

    /** Optimistic-retry limit used by append/prepend and incr/decr loops. */
    private const int ATOMIC_RETRY_LIMIT = 5;

    #[\Override]
    protected function createState(?string $persistentId): IgniteClientState
    {
        return IgniteClientState::createFresh($persistentId);
    }

    #[\Override]
    protected function defaultPort(): int
    {
        return Internal\IgniteProtocol::DEFAULT_PORT;
    }

    /**
     * Ignite's thin-client binary protocol marshals keys as plain byte arrays
     * inside a 4-byte length prefix, so the wire ceiling is {@code 2^31 - 1}.
     * We keep a generous-but-sane cap aligned with the Redis backend.
     */
    #[\Override]
    public function maxKeyLength(): int
    {
        return 65_536;
    }

    #[\Override]
    public function onPoolInvalidated(): void
    {
        $st = $this->st();
        foreach ($st->clientByServerIndex as $idx => $client) {
            try {
                $client->disconnect();
            } catch (\Throwable) {
            }

            unset($st->clientByServerIndex[$idx]);
        }

        $st->cacheIdByServerIndex = [];
    }

    #[\Override]
    public function isUnsupportedOption(int $option): bool
    {
        return false;
    }

    #[\Override]
    public function unsupportedOptionMessage(): string
    {
        return 'option is not supported by the Ignite-backed client';
    }

    #[\Override]
    protected function flushNetworkWrites(): void
    {
    }

    private function st(): IgniteClientState
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
                // {@see readEntry()} sets RES_PAYLOAD_FAILURE when the value
                // codec rejects a hit (e.g. decryption/auth-tag mismatch);
                // RES_NOTFOUND would mask the crypto error as a plain miss.
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
            foreach ($this->groupKeysByServer($keys, $serverKey) as $idx => $pairs) {
                foreach ($pairs as [$orig, $pk]) {
                    $entry = $this->readEntry($pk, $idx);
                    if ($entry instanceof CacheEntry) {
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
            foreach ($this->groupKeysByServer($keys, $serverKey) as $idx => $pairs) {
                foreach ($pairs as [$orig, $pk]) {
                    $entry = $this->readEntry($pk, $idx);
                    if (!$entry instanceof CacheEntry) {
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
        try {
            foreach ($this->groupKeysByServer($keys, $serverKey) as $idx => $pairs) {
                foreach ($pairs as [$orig, $pk]) {
                    $entry = $this->readEntry($pk, $idx);
                    if (!$entry instanceof CacheEntry) {
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
        if (!$this->checkKeyInternal($pk) || (null !== $serverKey && !$this->checkKeyInternal($serverKey))) {
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
            [$payload, $flags] = $this->encodePayload($value);
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

        try {
            $cacheId = $this->cacheIdFor($idx);
            $client = $this->clientFor($idx);

            return match ($mode) {
                StoreMode::Add => $this->storeAddViaPutIfAbsent($client, $cacheId, $pk, $payload, $flags),
                StoreMode::Replace => $this->storeReplace($client, $cacheId, $pk, $payload, $flags),
                default => $this->storeSetOrCas($client, $cacheId, $pk, $payload, $flags, $casToken),
            };
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
            if (!$this->doStore((string) $key, $value, $expiration, StoreMode::Set, $serverKey, null)) {
                $ok = false;
            }
        }

        $this->setResult($ok ? self::RES_SUCCESS : self::RES_SOME_ERRORS);

        return $ok;
    }

    #[\Override]
    protected function doTouch(string $key, int $expiration, ?string $serverKey): bool
    {
        return $this->executeKeyed($key, $serverKey, function (int $idx, string $pk): bool {
            if (!$this->clientFor($idx)->cacheContainsKey($this->cacheIdFor($idx), $pk)) {
                $this->setResult(self::RES_NOTFOUND);

                return false;
            }

            $this->setResult(self::RES_SUCCESS);

            return true;
        });
    }

    #[\Override]
    protected function doDelete(string $key, ?string $serverKey, int $time): bool
    {
        // PECL parity: bad key wins over delete-time, delete-time wins over server pool.
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

        $idx = $this->pickServerIndex($serverKey, $key);
        try {
            if (!$this->clientFor($idx)->cacheRemoveKey($this->cacheIdFor($idx), $pk)) {
                $this->setResult(self::RES_NOTFOUND);

                return false;
            }
        } catch (\Throwable $throwable) {
            $this->recordServerFailure($idx, $throwable);
            $this->setResult(self::RES_FAILURE, $throwable->getMessage());

            return false;
        }

        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    #[\Override]
    protected function doArith(string $key, int $offset, bool $decrement, ?string $serverKey, int $initialValue, int $expiry, bool $autoCreate = false): int|false
    {
        if ($offset < 0) {
            trigger_error('offset cannot be a negative value', \E_USER_WARNING);
            $this->setResult(self::RES_INVALID_ARGUMENTS);

            return false;
        }

        return $this->executeKeyed($key, $serverKey, function (int $idx, string $pk) use ($offset, $decrement, $initialValue, $autoCreate): int|false {
            $client = $this->clientFor($idx);
            $cacheId = $this->cacheIdFor($idx);

            for ($attempt = 0; $attempt < self::ATOMIC_RETRY_LIMIT; ++$attempt) {
                $oldBytes = $client->cacheGet($cacheId, $pk);
                if (null === $oldBytes) {
                    if (!$autoCreate) {
                        $this->setResult(self::RES_NOTFOUND);

                        return false;
                    }

                    $flags = 0;
                    ValueCodec::setType($flags, ValueCodec::TYPE_LONG);
                    $seedBytes = IgniteCacheCodec::encodeWrapper($this->randomCas(), $flags, (string) $initialValue);
                    if ($client->cachePutIfAbsent($cacheId, $pk, $seedBytes)) {
                        $this->setResult(self::RES_SUCCESS);

                        return $initialValue;
                    }

                    continue;
                }

                $entry = IgniteCacheCodec::decodeWrapper($oldBytes);
                if (null === $entry) {
                    $this->setResult(self::RES_NOTSTORED);

                    return false;
                }

                [, $flagsInt, $payload] = $entry;
                if (ValueCodec::TYPE_LONG !== ValueCodec::getType($flagsInt) || ValueCodec::hasCompression($flagsInt)) {
                    $this->setResult(self::RES_NOTSTORED);

                    return false;
                }

                $current = (int) $payload;
                $delta = $decrement ? -$offset : $offset;
                $next = $current + $delta;
                if ($decrement && $next < 0) {
                    $next = 0;
                }

                $newBytes = IgniteCacheCodec::encodeWrapper($this->randomCas(), $flagsInt, (string) $next);
                if ($client->cacheReplaceIfEquals($cacheId, $pk, $oldBytes, $newBytes)) {
                    $this->setResult(self::RES_SUCCESS);

                    return $next;
                }
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
                $client = $this->clientFor($i);
                $cacheId = $this->cacheIdFor($i);
                $out[$label] = $this->buildStats($client, $cacheId, $type);
            } catch (\Throwable $throwable) {
                $this->recordServerFailure($i, $throwable);
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
                $out[$label] = $this->clientFor($i)->getServerVersion();
            } catch (\Throwable $throwable) {
                $this->recordServerFailure($i, $throwable);
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
        $st = $this->st();
        if ([] === $st->selector->getServers()) {
            $this->setResult(self::RES_NO_SERVERS);

            return false;
        }

        if ($delay > 0) {
            $this->setResult(self::RES_NOT_SUPPORTED, 'flush delay not supported on Ignite');

            return false;
        }

        $ok = true;
        foreach (array_keys($st->selector->getServers()) as $i) {
            try {
                $this->clientFor($i)->cacheClear($this->cacheIdFor($i));
            } catch (\Throwable $throwable) {
                $this->recordServerFailure($i, $throwable);
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
                foreach ($this->clientFor($i)->cacheScanKeys($this->cacheIdFor($i)) as $key) {
                    $keys[] = $key;
                }

                $hadSuccess = true;
            } catch (\Throwable $throwable) {
                $this->recordServerFailure($i, $throwable);
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
    // Storage helpers
    // -----------------------------------------------------------------------

    private function storeSetOrCas(NativeIgniteClient $client, int $cacheId, string $pk, string $payload, int $flags, ?string $casToken): bool
    {
        if (null === $casToken || '' === $casToken) {
            $client->cachePut($cacheId, $pk, IgniteCacheCodec::encodeWrapper($this->randomCas(), $flags, $payload));
            $this->setResult(self::RES_SUCCESS);

            return true;
        }

        $expected = $casToken;
        for ($attempt = 0; $attempt < self::ATOMIC_RETRY_LIMIT; ++$attempt) {
            $oldBytes = $client->cacheGet($cacheId, $pk);
            if (null === $oldBytes) {
                $this->setResult(self::RES_NOTFOUND);

                return false;
            }

            $entry = IgniteCacheCodec::decodeWrapper($oldBytes);
            if (null === $entry) {
                $this->setResult(self::RES_NOTSTORED);

                return false;
            }

            [$currentCas] = $entry;
            if ((string) $currentCas !== $expected) {
                $this->setResult(self::RES_DATA_EXISTS);

                return false;
            }

            $newBytes = IgniteCacheCodec::encodeWrapper($this->randomCas(), $flags, $payload);
            if ($client->cacheReplaceIfEquals($cacheId, $pk, $oldBytes, $newBytes)) {
                $this->setResult(self::RES_SUCCESS);

                return true;
            }
        }

        $this->setResult(self::RES_DATA_EXISTS);

        return false;
    }

    private function storeAddViaPutIfAbsent(NativeIgniteClient $client, int $cacheId, string $pk, string $payload, int $flags): bool
    {
        $wrapper = IgniteCacheCodec::encodeWrapper($this->randomCas(), $flags, $payload);
        if (!$client->cachePutIfAbsent($cacheId, $pk, $wrapper)) {
            $this->setResult(self::RES_NOTSTORED);

            return false;
        }

        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    private function storeReplace(NativeIgniteClient $client, int $cacheId, string $pk, string $payload, int $flags): bool
    {
        $wrapper = IgniteCacheCodec::encodeWrapper($this->randomCas(), $flags, $payload);
        if (!$client->cacheReplace($cacheId, $pk, $wrapper)) {
            $this->setResult(self::RES_NOTSTORED);

            return false;
        }

        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    /**
     * Server-side append/prepend implemented as an optimistic retry loop on
     * top of {@code REPLACE_IF_EQUALS}: read existing wrapper, concat the
     * piece in-process, swap the entry atomically. The upstream
     * {@code OPT_COMPRESSION = false} guard means we are always operating on
     * a raw byte payload, so concatenation matches memcached semantics.
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
        try {
            $client = $this->clientFor($idx);
            $cacheId = $this->cacheIdFor($idx);

            for ($attempt = 0; $attempt < self::ATOMIC_RETRY_LIMIT; ++$attempt) {
                $oldBytes = $client->cacheGet($cacheId, $pk);
                if (null === $oldBytes) {
                    $this->setResult(self::RES_NOTSTORED);

                    return false;
                }

                $entry = IgniteCacheCodec::decodeWrapper($oldBytes);
                if (null === $entry) {
                    $this->setResult(self::RES_NOTSTORED);

                    return false;
                }

                [, $flagsInt, $existingPayload] = $entry;
                if (ValueCodec::TYPE_STRING !== ValueCodec::getType($flagsInt) || ValueCodec::hasCompression($flagsInt)) {
                    $this->setResult(self::RES_NOTSTORED);

                    return false;
                }

                $newPayload = StoreMode::Append === $mode ? $existingPayload.$value : $value.$existingPayload;
                $newBytes = IgniteCacheCodec::encodeWrapper($this->randomCas(), $flagsInt, $newPayload);
                if ($client->cacheReplaceIfEquals($cacheId, $pk, $oldBytes, $newBytes)) {
                    $this->setResult(self::RES_SUCCESS);

                    return true;
                }
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
    // Internals
    // -----------------------------------------------------------------------

    private function readEntry(string $pk, int $serverIndex): ?CacheEntry
    {
        $bytes = $this->clientFor($serverIndex)->cacheGet($this->cacheIdFor($serverIndex), $pk);
        if (null === $bytes) {
            return null;
        }

        $decoded = IgniteCacheCodec::decodeWrapper($bytes);
        if (null === $decoded) {
            return null;
        }

        [$cas, $flags, $payload] = $decoded;
        try {
            $value = ValueCodec::decode(
                $payload,
                $flags,
                $this->optionInt(self::OPT_SERIALIZER, self::SERIALIZER_PHP),
                $this->optionBool(self::OPT_ALLOW_SERIALIZED_CLASSES, false),
                $this->encodingContext(),
            );
        } catch (\Throwable) {
            $this->setResult(self::RES_PAYLOAD_FAILURE);

            return null;
        }

        return new CacheEntry($value, $this->casValue((string) $cas), ValueCodec::getUserFlags($flags));
    }

    private function clientFor(int $serverIndex): NativeIgniteClient
    {
        $st = $this->st();
        if (isset($st->clientByServerIndex[$serverIndex])) {
            return $st->clientByServerIndex[$serverIndex];
        }

        $servers = $st->selector->getServers();
        $server = $servers[$serverIndex] ?? null;
        if (null === $server) {
            throw new \RuntimeException('invalid server index');
        }

        $client = new NativeIgniteClient($server['host'], $server['port'], $this->readWriteTimeoutSeconds());
        $client->connect();
        $st->clientByServerIndex[$serverIndex] = $client;

        return $client;
    }

    private function cacheIdFor(int $serverIndex): int
    {
        $st = $this->st();
        if (isset($st->cacheIdByServerIndex[$serverIndex])) {
            return $st->cacheIdByServerIndex[$serverIndex];
        }

        $cacheId = $this->clientFor($serverIndex)->getOrCreateCache(self::CACHE_NAME);
        $st->cacheIdByServerIndex[$serverIndex] = $cacheId;

        return $cacheId;
    }

    private function readWriteTimeoutSeconds(): float
    {
        $recv = $this->optionInt(self::OPT_RECV_TIMEOUT, 0);
        $send = $this->optionInt(self::OPT_SEND_TIMEOUT, 0);
        $ms = max($recv, $send);

        return $ms > 0 ? $ms / 1000.0 : 0.0;
    }

    /**
     * Produce a positive 63-bit CAS token. Using {@code random_int} avoids the
     * cross-process collision risk of a local counter and keeps the value
     * inside PHP's native signed int range so callers always receive an
     * {@code int} (not a float) from {@see casValue()}.
     */
    private function randomCas(): int
    {
        return random_int(1, \PHP_INT_MAX);
    }

    /**
     * @return array{0:string,1:int}
     */
    private function encodePayload(mixed $value): array
    {
        $st = $this->st();

        return ValueCodec::encode(
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
    }

    /**
     * @return array<string, int|float|string>
     */
    private function buildStats(NativeIgniteClient $client, int $cacheId, ?string $type): array
    {
        $count = $client->cacheGetSize($cacheId);
        $snapshot = $client->getStatsSnapshot();

        return match ($type) {
            'items' => IgniteStatsAsMemcached::items($count),
            'slabs' => IgniteStatsAsMemcached::slabs($snapshot, $count),
            'sizes' => IgniteStatsAsMemcached::sizes(),
            default => IgniteStatsAsMemcached::general($snapshot, $count),
        };
    }
}
