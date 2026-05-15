<?php

declare(strict_types=1);

namespace PureCache\Ignite;

use PureCache\AbstractCacheClient;
use PureCache\Ignite\Internal\IgniteCacheCodec;
use PureCache\Internal\CacheEntry;
use PureCache\Internal\Expiration;
use PureCache\Internal\PersistentStateRegistry;
use PureCache\Internal\StoreMode;
use PureCache\Internal\ValueCodec;

/**
 * PECL {@code \Memcached}-shaped client backed by Apache Ignite via the native
 * thin-client binary protocol (port 10800 by default).
 *
 * Each PureCache entry is stored as a single {@code byte[]} in a shared cache.
 * The byte array carries an in-band 24-byte header — CAS, F-flags,
 * {@code expireAt} (absolute Unix timestamp), payload length — that lets us
 * preserve memcached semantics on top of an engine that has no built-in
 * CAS counter and whose v1.2.0 thin-client protocol does not expose a
 * per-entry TTL opcode:
 *  - `set`/`add`/`replace`/`append`/`prepend`/`cas`/`incr`/`decr` rotate the
 *    CAS field every time they write.
 *  - `cas($token, …)` compares against that header and uses Ignite's atomic
 *    {@code REPLACE_IF_EQUALS} to commit, so the check + write is one server
 *    round-trip without any leaked race window.
 *  - `append`/`prepend` and `increment`/`decrement` use the same operator in
 *    a short optimistic retry loop because Ignite has no scriptable atomic
 *    arithmetic equivalent to the Redis Lua scripts.
 *  - TTL is enforced lazily: every {@see readEntry()} checks {@code expireAt}
 *    against the wall clock and treats stale entries as a miss, best-effort
 *    deleting them so capacity gets reclaimed. {@code touch()} rewrites the
 *    {@code expireAt} field while preserving CAS (memcached's
 *    {@code touch} does not bump CAS); {@code append}/{@code prepend} and
 *    {@code incr}/{@code decr} preserve the existing {@code expireAt}
 *    (memcached's atomic mutators don't reset TTL).
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
        $clients = $st->clientByServerIndex;
        $st->clientByServerIndex = [];
        $st->cacheIdByServerIndex = [];
        foreach ($clients as $client) {
            try {
                $client->disconnect();
            } catch (\Throwable) {
            }
        }
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

    /**
     * Ignite's thin-client transport is request/response per opcode over a
     * single TCP stream — there is no client-side write buffer to drain.
     * This stays empty by design so the abstract contract is satisfied
     * without paying for an extra syscall.
     */
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
            foreach ($this->readEntriesBatched($keys, $serverKey) as [$orig, $entry]) {
                $found[$orig] = $this->valueForGetFlags($entry, $getFlags);
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
            foreach ($this->readEntriesBatched($keys, $serverKey) as [$orig, $entry]) {
                $results[] = $this->delayedEntry($orig, $entry, $withCas);
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
            foreach ($this->readEntriesBatched($keys, $serverKey) as [$orig, $entry]) {
                $valueCb($this, $this->delayedEntry($orig, $entry, $withCas));
            }
        } catch (\Throwable $throwable) {
            $this->setResult(self::RES_FAILURE, $throwable->getMessage());

            return false;
        }

        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    /**
     * Single batched multi-get path used by {@see doGetMulti()},
     * {@see doFetchBatch()} and {@see doGetDelayedValueCallback()}.
     *
     * Collapses what used to be N round-trips per shard into a single
     * {@code OP_CACHE_GET_ALL} (one RTT per shard), and threads lazy
     * expiration through {@see materializeEntry()} so a stale wrapper
     * doesn't materialise as a hit.
     *
     * @param list<string> $keys
     *
     * @return \Generator<int, array{0:string,1:CacheEntry}>
     */
    private function readEntriesBatched(array $keys, ?string $serverKey): \Generator
    {
        foreach ($this->groupKeysByServer($keys, $serverKey) as $idx => $pairs) {
            $client = $this->clientFor($idx);
            $cacheId = $this->cacheIdFor($idx);

            $prefixedKeys = array_map(static fn (array $pair): string => $pair[1], $pairs);
            $rawByPk = $client->cacheGetAll($cacheId, $prefixedKeys);
            // We iterate over the original input order, not the response,
            // so callers (e.g. {@see doFetchBatch()}) see deterministic
            // result ordering even if Ignite shuffles the map keys.
            foreach ($pairs as [$orig, $pk]) {
                if (!isset($rawByPk[$pk])) {
                    continue;
                }

                $entry = $this->materializeEntry($rawByPk[$pk], $pk, $client, $cacheId);
                if ($entry instanceof CacheEntry) {
                    yield [$orig, $entry];
                }
            }
        }
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

        $encoded = $this->encodeForStore($value);
        if (null === $encoded) {
            return false;
        }

        [$payload, $flags] = $encoded;
        $expireAt = $this->absoluteExpiry($expiration);

        return $this->retryStoreOnFailure($serverKey, $key, function (int $idx) use ($pk, $payload, $flags, $expireAt, $mode, $casToken): bool {
            try {
                $cacheId = $this->cacheIdFor($idx);
                $client = $this->clientFor($idx);

                return match ($mode) {
                    StoreMode::Add => $this->storeAddViaPutIfAbsent($client, $cacheId, $pk, $payload, $flags, $expireAt),
                    StoreMode::Replace => $this->storeReplace($client, $cacheId, $pk, $payload, $flags, $expireAt),
                    default => $this->storeSetOrCas($client, $cacheId, $pk, $payload, $flags, $expireAt, $casToken),
                };
            } catch (\Throwable $throwable) {
                $this->recordServerFailure($idx, $throwable);
                $this->setResult(self::RES_FAILURE, $throwable->getMessage());

                return false;
            }
        });
    }

    /**
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
        foreach ($items as $key => $value) {
            // Each item carries the same TTL — {@see doStore()} now reads
            // {@code $expiration} instead of dropping it, so set-multi
            // finally matches memcached's per-call TTL semantics.
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
        $expireAt = $this->absoluteExpiry($expiration);

        return $this->executeKeyed($key, $serverKey, function (int $idx, string $pk) use ($expireAt): bool {
            $client = $this->clientFor($idx);
            $cacheId = $this->cacheIdFor($idx);

            // Memcached's `touch` updates only the TTL — CAS is preserved.
            // We loop with REPLACE_IF_EQUALS so a concurrent writer can't
            // be silently clobbered by our wrapper rewrite.
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

                [$cas, $flagsInt, $oldExpireAt, $payload] = $entry;
                if ($this->isExpired($oldExpireAt)) {
                    $this->bestEffortRemove($client, $cacheId, $pk);
                    $this->setResult(self::RES_NOTFOUND);

                    return false;
                }

                $newBytes = IgniteCacheCodec::encodeWrapper($cas, $flagsInt, $expireAt, $payload);
                if ($client->cacheReplaceIfEquals($cacheId, $pk, $oldBytes, $newBytes)) {
                    $this->setResult(self::RES_SUCCESS);

                    return true;
                }
            }

            $this->setResult(self::RES_NOTSTORED);

            return false;
        }, fanoutWrite: true);
    }

    #[\Override]
    protected function doDelete(string $key, ?string $serverKey, int $time): bool
    {
        return $this->executeDelete($key, $serverKey, $time, fn (string $pk): bool => $this->writeFanout($serverKey, $key, function (int $idx) use ($pk): bool {
            try {
                if (!$this->clientFor($idx)->cacheRemoveKey($this->cacheIdFor($idx), $pk)) {
                    return $this->failResult(self::RES_NOTFOUND);
                }
            } catch (\Throwable $throwable) {
                $this->recordServerFailure($idx, $throwable);
                $this->setResult(self::RES_FAILURE, $throwable->getMessage());

                return false;
            }

            return $this->okResult(self::RES_SUCCESS);
        }));
    }

    #[\Override]
    protected function doArith(string $key, int $offset, bool $decrement, ?string $serverKey, int $initialValue, int $expiry, bool $autoCreate = false): int|false
    {
        if ($offset < 0) {
            trigger_error('offset cannot be a negative value', \E_USER_WARNING);
            $this->setResult(self::RES_INVALID_ARGUMENTS);

            return false;
        }

        $seedExpireAt = $this->absoluteExpiry($expiry);

        return $this->executeKeyed($key, $serverKey, function (int $idx, string $pk) use ($offset, $decrement, $initialValue, $autoCreate, $seedExpireAt): int|false {
            $client = $this->clientFor($idx);
            $cacheId = $this->cacheIdFor($idx);

            for ($attempt = 0; $attempt < self::ATOMIC_RETRY_LIMIT; ++$attempt) {
                $oldBytes = $client->cacheGet($cacheId, $pk);
                if (null === $oldBytes || $this->isExpiredWrapperBytes($oldBytes)) {
                    if (null !== $oldBytes) {
                        $this->bestEffortRemove($client, $cacheId, $pk);
                    }

                    if (!$autoCreate) {
                        $this->setResult(self::RES_NOTFOUND);

                        return false;
                    }

                    $flags = 0;
                    ValueCodec::setType($flags, ValueCodec::TYPE_LONG);
                    $seedBytes = IgniteCacheCodec::encodeWrapper($this->randomCas(), $flags, $seedExpireAt, (string) $initialValue);
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

                [, $flagsInt, $oldExpireAt, $payload] = $entry;
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

                // Memcached's incr/decr leave the existing TTL untouched —
                // only the {@code initial}/{@code expiry} pair on the
                // auto-create branch above gets to set {@code expireAt}.
                $newBytes = IgniteCacheCodec::encodeWrapper($this->randomCas(), $flagsInt, $oldExpireAt, (string) $next);
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
        if (!$this->ensureServersAvailable()) {
            return false;
        }

        $result = $this->collectFromServers(fn (int $i): array => $this->buildStats($this->clientFor($i), $this->cacheIdFor($i), $type), false);

        if (!$result['allOk'] && !$result['anyOk']) {
            $this->setResult(self::RES_FAILURE, 'Ignite stats failed: cluster version query requires SQL access to SYS.NODES');

            return false;
        }

        $this->setResult($result['allOk'] ? self::RES_SUCCESS : self::RES_SOME_ERRORS);

        return $result['values'];
    }

    /**
     * @return array<string, string>|false
     */
    #[\Override]
    protected function doGetVersion(): array|false
    {
        if (!$this->ensureServersAvailable()) {
            return false;
        }

        $result = $this->collectFromServers(
            fn (int $i): string => $this->clientFor($i)->resolveProductVersion($this->cacheIdFor($i)),
            '',
        );

        if (!$result['allOk'] && !$result['anyOk']) {
            $this->setResult(self::RES_FAILURE, 'Ignite version query requires SQL access to SYS.NODES');

            return false;
        }

        $this->setResult($result['allOk'] ? self::RES_SUCCESS : self::RES_SOME_ERRORS);

        return $result['values'];
    }

    #[\Override]
    protected function doFlush(int $delay): bool
    {
        if (!$this->ensureServersAvailable()) {
            return false;
        }

        if ($delay > 0) {
            $this->setResult(self::RES_NOT_SUPPORTED, 'flush delay not supported on Ignite');

            return false;
        }

        $result = $this->collectFromServers(function (int $i): bool {
            $this->clientFor($i)->cacheClear($this->cacheIdFor($i));

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
        if (!$this->ensureServersAvailable()) {
            return false;
        }

        $result = $this->collectFromServers(fn (int $i): array => $this->clientFor($i)->cacheScanKeys($this->cacheIdFor($i)), []);

        if (!$result['allOk'] && !$result['anyOk']) {
            $this->setResult(self::RES_FAILURE);

            return false;
        }

        $this->setResult($result['allOk'] ? self::RES_SUCCESS : self::RES_SOME_ERRORS);

        // Each key is routed to exactly one shard, so the per-shard
        // lists are already disjoint — a global `array_unique()` here
        // would just pay an O(N log N) string compare for nothing.
        return array_merge(...array_values($result['values']));
    }

    // -----------------------------------------------------------------------
    // Storage helpers
    // -----------------------------------------------------------------------

    private function storeSetOrCas(NativeIgniteClient $client, int $cacheId, string $pk, string $payload, int $flags, int $expireAt, ?string $casToken): bool
    {
        if (null === $casToken || '' === $casToken) {
            $client->cachePut($cacheId, $pk, IgniteCacheCodec::encodeWrapper($this->randomCas(), $flags, $expireAt, $payload));
            $this->setResult(self::RES_SUCCESS);

            return true;
        }

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

            [$currentCas, , $oldExpireAt] = $entry;
            // An entry whose absolute TTL has already elapsed must look
            // like a miss to the CAS path too — otherwise a stale token
            // could "successfully" rewrite a ghost row.
            if ($this->isExpired($oldExpireAt)) {
                $this->bestEffortRemove($client, $cacheId, $pk);
                $this->setResult(self::RES_NOTFOUND);

                return false;
            }

            if ((string) $currentCas !== $casToken) {
                $this->setResult(self::RES_DATA_EXISTS);

                return false;
            }

            $newBytes = IgniteCacheCodec::encodeWrapper($this->randomCas(), $flags, $expireAt, $payload);
            if ($client->cacheReplaceIfEquals($cacheId, $pk, $oldBytes, $newBytes)) {
                $this->setResult(self::RES_SUCCESS);

                return true;
            }
        }

        $this->setResult(self::RES_DATA_EXISTS);

        return false;
    }

    private function storeAddViaPutIfAbsent(NativeIgniteClient $client, int $cacheId, string $pk, string $payload, int $flags, int $expireAt): bool
    {
        $wrapper = IgniteCacheCodec::encodeWrapper($this->randomCas(), $flags, $expireAt, $payload);
        if ($client->cachePutIfAbsent($cacheId, $pk, $wrapper)) {
            $this->setResult(self::RES_SUCCESS);

            return true;
        }

        // PUT_IF_ABSENT refused — either the slot is genuinely held by a
        // live entry (correct memcached "not stored") or it's a tombstone
        // whose absolute TTL has elapsed but lazy expiration hasn't fired
        // yet. In the latter case we evict it and retry once so `add()`
        // matches PECL semantics ("only fails when the key is alive").
        $existing = $client->cacheGet($cacheId, $pk);
        if (null !== $existing && $this->isExpiredWrapperBytes($existing)) {
            $this->bestEffortRemove($client, $cacheId, $pk);
            if ($client->cachePutIfAbsent($cacheId, $pk, $wrapper)) {
                $this->setResult(self::RES_SUCCESS);

                return true;
            }
        }

        $this->setResult(self::RES_NOTSTORED);

        return false;
    }

    private function storeReplace(NativeIgniteClient $client, int $cacheId, string $pk, string $payload, int $flags, int $expireAt): bool
    {
        // A stale-but-not-yet-evicted entry would fool plain REPLACE into
        // succeeding even though memcached would call the key gone. Read
        // first, evict the tombstone, then let REPLACE return NOT_STORED.
        $existing = $client->cacheGet($cacheId, $pk);
        if (null !== $existing && $this->isExpiredWrapperBytes($existing)) {
            $this->bestEffortRemove($client, $cacheId, $pk);
            $this->setResult(self::RES_NOTSTORED);

            return false;
        }

        $wrapper = IgniteCacheCodec::encodeWrapper($this->randomCas(), $flags, $expireAt, $payload);
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
     *
     * Wrapped in {@see retryStoreOnFailure()} so a connection-level fault
     * doesn't permanently fail the call — the other shard-aware store
     * paths (set/add/replace/cas) already get that treatment, and there's
     * no reason concat should be uniquely fragile.
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

        return $this->retryStoreOnFailure($serverKey, $key, function (int $idx) use ($pk, $value, $mode): bool {
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

                    [, $flagsInt, $oldExpireAt, $existingPayload] = $entry;
                    if ($this->isExpired($oldExpireAt)) {
                        $this->bestEffortRemove($client, $cacheId, $pk);
                        $this->setResult(self::RES_NOTSTORED);

                        return false;
                    }

                    if (ValueCodec::TYPE_STRING !== ValueCodec::getType($flagsInt) || ValueCodec::hasCompression($flagsInt)) {
                        $this->setResult(self::RES_NOTSTORED);

                        return false;
                    }

                    $newPayload = StoreMode::Append === $mode ? $existingPayload.$value : $value.$existingPayload;
                    // Append/prepend in memcached preserve the existing
                    // TTL — only the payload (and consequently the CAS)
                    // rotate, so {@code $oldExpireAt} flows through.
                    $newBytes = IgniteCacheCodec::encodeWrapper($this->randomCas(), $flagsInt, $oldExpireAt, $newPayload);
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
        });
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function readEntry(string $pk, int $serverIndex): ?CacheEntry
    {
        $client = $this->clientFor($serverIndex);
        $cacheId = $this->cacheIdFor($serverIndex);

        $bytes = $client->cacheGet($cacheId, $pk);
        if (null === $bytes) {
            return null;
        }

        return $this->materializeEntry($bytes, $pk, $client, $cacheId);
    }

    /**
     * Shared decode path for {@see readEntry()} and the batched multi-get
     * variant: turn a raw wrapper blob into a {@see CacheEntry}, doing
     * lazy expiration along the way. A stale wrapper is treated as a
     * miss and best-effort removed from Ignite so capacity comes back
     * without waiting for the next write.
     */
    private function materializeEntry(string $bytes, string $pk, NativeIgniteClient $client, int $cacheId): ?CacheEntry
    {
        $decoded = IgniteCacheCodec::decodeWrapper($bytes);
        if (null === $decoded) {
            return null;
        }

        [$cas, $flags, $expireAt, $payload] = $decoded;
        if ($this->isExpired($expireAt)) {
            $this->bestEffortRemove($client, $cacheId, $pk);

            return null;
        }

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

        $client = new NativeIgniteClient($server['host'], $server['port'], $this->recvSendTimeoutSeconds());
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
     * Convert a memcached-style {@code $expiration} parameter (0 / relative
     * seconds / absolute Unix timestamp) into the absolute timestamp we
     * store inside the wrapper. {@code 0} means "never expires".
     */
    private function absoluteExpiry(int $expiration): int
    {
        return Expiration::toAbsoluteUnixTime($expiration);
    }

    /**
     * @param int $expireAt absolute Unix timestamp; {@code 0} means "no expiry"
     */
    private function isExpired(int $expireAt): bool
    {
        return $expireAt > 0 && $expireAt <= time();
    }

    /**
     * Peek at a raw wrapper without fully decoding it — useful in the
     * {@code add}/{@code replace}/{@code incr} fast paths where we only
     * need to know whether the existing slot is a live entry or a
     * not-yet-evicted tombstone.
     */
    private function isExpiredWrapperBytes(string $bytes): bool
    {
        $decoded = IgniteCacheCodec::decodeWrapper($bytes);
        if (null === $decoded) {
            return false;
        }

        return $this->isExpired($decoded[2]);
    }

    /**
     * Lazy-expiration eviction. A failure here (network blip, contention
     * with a concurrent writer) is non-fatal — the entry will simply be
     * skipped on the next read and removed then. We deliberately swallow
     * the exception so a get path can't be poisoned by a stale row.
     */
    private function bestEffortRemove(NativeIgniteClient $client, int $cacheId, string $pk): void
    {
        try {
            $client->cacheRemoveKey($cacheId, $pk);
        } catch (\Throwable) {
            // intentional: lazy expiration is opportunistic
        }
    }

    /**
     * @return array<string, int|float|string>
     */
    private function buildStats(NativeIgniteClient $client, int $cacheId, ?string $type): array
    {
        $client->resolveProductVersion($cacheId);
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
