<?php

declare(strict_types=1);

namespace PureCache\Memcached;

use PureCache\AbstractCacheClient;
use PureCache\Internal\CacheEntry;
use PureCache\Internal\ItemSizeGuard;
use PureCache\Internal\PersistentStateRegistry;
use PureCache\Internal\StoreMode;
use PureCache\Internal\ValueCodec;
use PureCache\Memcached\Internal\DecodedMetaValue;
use PureCache\Memcached\Internal\MemcachedClientCore;
use PureCache\Memcached\Internal\MetaCommandBuilder;
use PureCache\Memcached\Internal\MetaReader;
use PureCache\Memcached\Internal\MetaResult;
use PureCache\Memcached\Internal\MetaValueReader;
use PureCache\Memcached\Internal\StreamConnection;
use PureCache\Memcached\Internal\TextProtocolClient;

/**
 * Pure-PHP memcached client speaking the binary-safe meta protocol over plain TCP.
 *
 * Inherits the entire PECL {@code \Memcached} surface from {@see AbstractCacheClient}; this
 * subclass only implements the protocol-specific {@code doGet}/{@code doStore}/{@code doDelete}/… hooks
 * plus the persistent-pool registry keyed by {@code persistent_id}.
 *
 * @extends AbstractCacheClient<MemcachedClientCore>
 *
 * @psalm-suppress MixedAssignment
 */
final class MemcachedClient extends AbstractCacheClient
{
    /** @use PersistentStateRegistry<MemcachedClientCore> */
    use PersistentStateRegistry;

    #[\Override]
    protected function createState(?string $persistentId): MemcachedClientCore
    {
        return MemcachedClientCore::createFresh($persistentId);
    }

    #[\Override]
    protected function defaultPort(): int
    {
        return 11211;
    }

    #[\Override]
    public function onPoolInvalidated(): void
    {
        $this->core()->conn->closeAll();
    }

    #[\Override]
    public function onTimeoutsChanged(): void
    {
        $this->core()->rebuildConnectionManager();
    }

    #[\Override]
    public function isUnsupportedOption(int $option): bool
    {
        return false;
    }

    #[\Override]
    public function unsupportedOptionMessage(): string
    {
        return 'option is not supported by the pure PHP meta protocol client';
    }

    /**
     * Drain every per-connection write buffer. Each per-server flush failure is
     * attributed to the right shard via {@see recordServerFailure()} so
     * libmemcached-style {@code OPT_SERVER_FAILURE_LIMIT} /
     * {@code OPT_SERVER_TIMEOUT_LIMIT} accounting keeps working when
     * {@code OPT_BUFFER_WRITES} is on. The first captured throwable is
     * re-thrown so callers (and the existing `try { … } catch (\Throwable …)`
     * wrappers) still see exactly one exception per flush invocation.
     */
    #[\Override]
    protected function flushNetworkWrites(): void
    {
        $this->core()->conn->flushAllBuffers(function (int $serverIndex, \Throwable $throwable): void {
            $this->recordServerFailure($serverIndex, $throwable);
        });
    }

    private function core(): MemcachedClientCore
    {
        return $this->state();
    }

    /**
     * Keys per meta-protocol pipeline window on one TCP connection.
     * When {@see self::OPT_IO_KEY_PREFETCH} is 0, all keys for the shard are
     * sent before any response is read (maximum single-round pipelining).
     */
    private function metaGetPipelineChunkSize(int $pairCount): int
    {
        $prefetch = $this->optionInt(self::OPT_IO_KEY_PREFETCH, 0);

        return $prefetch > 0 ? $prefetch : max(1, $pairCount);
    }

    // -----------------------------------------------------------------------
    // Read paths
    // -----------------------------------------------------------------------

    #[\Override]
    protected function doGet(string $key, string $prefixedKey, ?string $serverKey, int $getFlags): mixed
    {
        return $this->executeKeyed($key, $serverKey, function (int $idx, string $pk) use ($getFlags): mixed {
            $c = $this->core()->conn->get($idx);
            $this->send($c, MetaCommandBuilder::metaGetValue($pk));

            $reader = $this->metaReader($c);
            $item = $this->readDecodedMetaValue($reader);
            if (false === $item) {
                return false;
            }

            if (!$item->found) {
                $this->setResult(self::RES_NOTFOUND);

                return false;
            }

            $this->setResult(self::RES_SUCCESS);

            return $this->valueForGetFlags($this->entryFromMeta($item), $getFlags);
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
        $core = $this->core();
        $byServer = $this->groupKeysByServer($keys, $serverKey);

        /** @var array<string, mixed> $found */
        $found = [];
        $hadFailure = false;
        $hadSuccess = false;
        $lastError = null;
        foreach ($byServer as $serverIdx => $pairs) {
            try {
                $c = $core->conn->get($serverIdx);
                $chunk = $this->metaGetPipelineChunkSize(\count($pairs));
                for ($offset = 0, $total = \count($pairs); $offset < $total; $offset += $chunk) {
                    $slice = \array_slice($pairs, $offset, $chunk);
                    foreach ($slice as [, $pk]) {
                        $this->send($c, MetaCommandBuilder::metaGetValue($pk));
                    }

                    $reader = $this->metaReader($c);
                    foreach ($slice as [$orig]) {
                        $item = $this->readDecodedMetaValue($reader);
                        if (false === $item) {
                            $hadFailure = true;
                            continue;
                        }

                        $hadSuccess = true;
                        if ($item->found) {
                            $found[$orig] = $this->valueForGetFlags($this->entryFromMeta($item), $getFlags);
                        }
                    }
                }
            } catch (\Throwable $throwable) {
                $this->recordServerFailure($serverIdx, $throwable);
                $lastError = $throwable;
                $hadFailure = true;
            }
        }

        // PECL parity: when at least one server-shard succeeded we keep its
        // results around and surface RES_SOME_ERRORS. Only when every shard
        // failed do we propagate a hard RES_FAILURE — losing the buffered
        // partial hits would silently mask a partial fan-out.
        if ($hadFailure && !$hadSuccess) {
            $this->setResult(self::RES_FAILURE, $lastError instanceof \Throwable ? $lastError->getMessage() : null);

            return false;
        }

        if ($hadFailure) {
            $this->setResult(self::RES_SOME_ERRORS, $lastError instanceof \Throwable ? $lastError->getMessage() : null);
        } else {
            // Either the fan-out was a clean sweep, or the input produced no
            // shards at all (defensive: getMultiCommon already filters empty
            // key lists). Either way we must set an explicit success result —
            // leaving resultCode stale would make every public getMulti caller
            // observe whichever code the previous operation set.
            $this->setResult(self::RES_SUCCESS);
        }

        return $found;
    }

    /**
     * @param list<string> $keys
     *
     * @return list<array<string, mixed>>|false
     */
    #[\Override]
    protected function doFetchBatch(array $keys, ?string $serverKey, bool $withCas): array|false
    {
        $core = $this->core();
        $results = [];
        $byServer = $this->groupKeysByServer($keys, $serverKey);
        $currentIdx = null;
        try {
            foreach ($byServer as $serverIdx => $pairs) {
                $currentIdx = $serverIdx;
                $c = $core->conn->get($serverIdx);
                $chunk = $this->metaGetPipelineChunkSize(\count($pairs));
                for ($offset = 0, $total = \count($pairs); $offset < $total; $offset += $chunk) {
                    $slice = \array_slice($pairs, $offset, $chunk);
                    foreach ($slice as [, $pk]) {
                        $this->send($c, MetaCommandBuilder::metaGetValue($pk));
                    }

                    $reader = $this->metaReader($c);
                    foreach ($slice as [$orig]) {
                        $item = $this->readDecodedMetaValue($reader);
                        if (false === $item) {
                            return false;
                        }

                        if ($item->found) {
                            $results[] = $this->delayedEntry($orig, $this->entryFromMeta($item), $withCas);
                        }
                    }
                }
            }
        } catch (\Throwable $throwable) {
            $this->recordServerFailure($currentIdx, $throwable);
            $this->setResult(self::RES_FAILURE, $throwable->getMessage());

            return false;
        }

        return $results;
    }

    /**
     * @param list<string>                                                $keys
     * @param callable(\PureCache\CacheClient, array<string, mixed>):void $valueCb
     */
    #[\Override]
    protected function doGetDelayedValueCallback(array $keys, ?string $serverKey, bool $withCas, callable $valueCb): bool
    {
        $this->flushNetworkWrites();
        $core = $this->core();
        $byServer = $this->groupKeysByServer($keys, $serverKey);

        $currentIdx = null;
        try {
            foreach ($byServer as $serverIdx => $pairs) {
                $currentIdx = $serverIdx;
                $c = $core->conn->get($serverIdx);
                $chunk = $this->metaGetPipelineChunkSize(\count($pairs));
                for ($offset = 0, $total = \count($pairs); $offset < $total; $offset += $chunk) {
                    $slice = \array_slice($pairs, $offset, $chunk);
                    foreach ($slice as [, $pk]) {
                        $this->send($c, MetaCommandBuilder::metaGetValue($pk));
                    }

                    $reader = $this->metaReader($c);
                    foreach ($slice as [$orig]) {
                        $item = $this->readDecodedMetaValue($reader);
                        if (false === $item) {
                            continue;
                        }

                        if (!$item->found) {
                            continue;
                        }

                        $valueCb($this, $this->delayedEntry($orig, $this->entryFromMeta($item), $withCas));
                    }
                }
            }
        } catch (\Throwable $throwable) {
            $this->recordServerFailure($currentIdx, $throwable);
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

        $cmd = $this->prepareStoreCommand($key, $value, $expiration, $mode, $serverKey, $casToken);
        if (false === $cmd) {
            return false;
        }

        if ([] === $this->core()->selector->getServers()) {
            $this->setResult(self::RES_NO_SERVERS);

            return false;
        }

        return $this->retryStoreOnFailure($serverKey, $key, function (int $idx) use ($cmd): bool {
            try {
                if (!$this->shouldBufferNoReplyWrite()) {
                    $this->flushNetworkWrites();
                }

                $c = $this->core()->conn->get($idx);
                $this->send($c, $cmd);
                if ($this->useNoReply()) {
                    $this->setResult(self::RES_SUCCESS);

                    return true;
                }

                $reader = $this->metaReader($c);
                $r = $reader->readOne(false);

                return $this->mapStoreResult($r);
            } catch (\Throwable $throwable) {
                $this->recordServerFailure($idx, $throwable);
                $this->setResult(self::RES_FAILURE, $throwable->getMessage());

                return false;
            }
        });
    }

    /**
     * Pipelined {@code setMulti} that honours {@code OPT_NUMBER_OF_REPLICAS}:
     * for each key the prepared meta-set command is appended both to the
     * primary's batch *and* to each replica's batch, then per-server batches
     * are sent in one shot and drained sequentially.
     *
     * Per-item outcome is decided exclusively by the primary slot. Replica
     * reply codes are inspected only to keep the pipelined stream aligned —
     * any non-{@code HD} reply or network exception on a replica is recorded
     * against the failure tracker (so {@code OPT_SERVER_FAILURE_LIMIT} keeps
     * working) but never surfaces as {@code RES_SOME_ERRORS}, matching the
     * single-key fan-out contract established by {@see writeFanout()}.
     *
     * Final result code follows PECL libmemcached's coarse {@code mset}
     * semantics: a single fatal primary exception → {@code RES_FAILURE};
     * otherwise {@code RES_SUCCESS} on a clean run or {@code RES_SOME_ERRORS}
     * if any item failed locally (encoding / size limit / no eligible shard)
     * or returned a non-{@code HD} reply from its primary.
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

        $core = $this->core();
        /** @var array<int, list<array{cmd: string, primary: bool}>> $batches */
        $batches = [];
        /** @var array<int, bool> $batchHasPrimary */
        $batchHasPrimary = [];
        $ok = true;
        foreach ($items as $key => $value) {
            $keyString = (string) $key;
            $prepared = $this->prepareStoreCommand($keyString, $value, $expiration, StoreMode::Set, $serverKey, null);
            if (false === $prepared) {
                $ok = false;
                continue;
            }

            $targets = $this->fanoutTargets($serverKey, $keyString);
            if (null === $targets) {
                $ok = false;
                continue;
            }

            $primaryIdx = $targets['primary'];
            $batches[$primaryIdx][] = ['cmd' => $prepared, 'primary' => true];
            $batchHasPrimary[$primaryIdx] = true;
            foreach ($targets['replicas'] as $replicaIdx) {
                $batches[$replicaIdx][] = ['cmd' => $prepared, 'primary' => false];
                $batchHasPrimary[$replicaIdx] ??= false;
            }
        }

        if (!$this->shouldBufferNoReplyWrite()) {
            $this->flushNetworkWrites();
        }

        $fatalMessage = null;
        foreach ($batches as $serverIdx => $entries) {
            try {
                $c = $core->conn->get($serverIdx);
                foreach ($entries as $entry) {
                    $this->send($c, $entry['cmd']);
                }

                if ($this->useNoReply()) {
                    $this->recordServerSuccess($serverIdx);

                    continue;
                }

                $reader = $this->metaReader($c);
                foreach ($entries as $entry) {
                    // Replica reply is consumed only to keep the pipeline
                    // aligned; its outcome must not leak into resultCode.
                    $reply = $reader->readOne(false);
                    if ($entry['primary'] && !$this->primaryStoreReplyOk($reply)) {
                        $ok = false;
                    }
                }

                $this->recordServerSuccess($serverIdx);
            } catch (\Throwable $throwable) {
                $this->recordServerFailure($serverIdx, $throwable);
                if ($batchHasPrimary[$serverIdx] ?? false) {
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

    #[\Override]
    protected function doTouch(string $key, int $expiration, ?string $serverKey): bool
    {
        if (!$this->shouldBufferNoReplyWrite()) {
            $this->flushNetworkWrites();
        }

        return $this->executeKeyed($key, $serverKey, function (int $idx, string $pk) use ($expiration): bool {
            $c = $this->core()->conn->get($idx);
            $noReply = $this->useNoReply();
            $this->send($c, MetaCommandBuilder::metaGetTouch($pk, $this->ttlToken($expiration), $noReply));
            if ($noReply) {
                return $this->okResult(self::RES_SUCCESS);
            }

            $reader = $this->metaReader($c);
            $r = $reader->readOne(false);
            if ($this->applyMetaWireError($r)) {
                return false;
            }

            return match ($r->code) {
                'HD' => $this->okResult(self::RES_SUCCESS),
                'EN' => $this->failResult(self::RES_NOTFOUND),
                default => $this->failResult(self::RES_FAILURE),
            };
        }, fanoutWrite: true);
    }

    #[\Override]
    protected function doDelete(string $key, ?string $serverKey, int $time): bool
    {
        return $this->executeDelete($key, $serverKey, $time, function (string $pk) use ($serverKey, $key): bool {
            if (!$this->shouldBufferNoReplyWrite()) {
                $this->flushNetworkWrites();
            }

            return $this->writeFanout($serverKey, $key, function (int $idx) use ($pk): bool {
                try {
                    $c = $this->core()->conn->get($idx);
                    $this->send($c, MetaCommandBuilder::metaDelete($pk, $this->useNoReply()));
                    if ($this->useNoReply()) {
                        return $this->okResult(self::RES_SUCCESS);
                    }

                    $reader = $this->metaReader($c);
                    $r = $reader->readOne(false);
                } catch (\Throwable $throwable) {
                    $this->recordServerFailure($idx, $throwable);
                    $this->setResult(self::RES_FAILURE, $throwable->getMessage());

                    return false;
                }

                if ($this->applyMetaWireError($r)) {
                    return false;
                }

                return match ($r->code) {
                    'HD' => $this->okResult(self::RES_SUCCESS),
                    'NF' => $this->failResult(self::RES_NOTFOUND),
                    default => $this->failResult(self::RES_FAILURE),
                };
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

        $this->flushNetworkWrites();

        return $this->executeKeyed($key, $serverKey, function (int $idx, string $pk) use ($offset, $decrement, $initialValue, $expiry, $autoCreate): int|false {
            $c = $this->core()->conn->get($idx);
            $this->send($c, MetaCommandBuilder::metaArith(
                $pk,
                $offset,
                $decrement,
                $autoCreate ? $initialValue : null,
                $autoCreate ? $expiry : null,
            ));
            $reader = $this->metaReader($c);
            $r = $reader->readArithmeticValue();
            if (MetaReader::CODE_ITEM_TOO_BIG === $r->code) {
                $this->setResult(self::RES_E2BIG, $r->errorMessage);

                return false;
            }

            if ($this->applyMetaWireError($r)) {
                return false;
            }

            if ('VA' === $r->code && null !== $r->value) {
                $this->setResult(self::RES_SUCCESS);

                return (int) trim($r->value);
            }

            if ('NF' === $r->code) {
                $this->setResult(self::RES_NOTFOUND);

                return false;
            }

            if ('NS' === $r->code) {
                $this->setResult(self::RES_NOTSTORED);

                return false;
            }

            $this->setResult(self::RES_FAILURE);

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
        $this->flushNetworkWrites();
        if (!$this->ensureServersAvailable()) {
            return false;
        }

        $core = $this->core();
        $result = $this->collectFromServers(static function (int $i) use ($core, $type): array|null {
            $st = TextProtocolClient::stats($core->conn->get($i), $type);

            return false === $st ? null : $st;
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
        $this->flushNetworkWrites();
        if (!$this->ensureServersAvailable()) {
            return false;
        }

        $core = $this->core();
        $result = $this->collectFromServers(static function (int $i) use ($core): ?string {
            $v = TextProtocolClient::version($core->conn->get($i));

            return false === $v ? null : $v;
        }, '');

        $this->setResult($result['allOk'] ? self::RES_SUCCESS : self::RES_SOME_ERRORS);

        return $result['values'];
    }

    #[\Override]
    protected function doFlush(int $delay): bool
    {
        $this->flushNetworkWrites();
        if (!$this->ensureServersAvailable()) {
            return false;
        }

        $core = $this->core();
        // collectFromServers treats `null` as "this shard failed", so we map
        // the protocol's `bool` outcome into `true|null` for the helper.
        $result = $this->collectFromServers(
            static fn (int $i): ?bool => TextProtocolClient::flushAll($core->conn->get($i), $delay) ? true : null,
            false,
        );

        $this->setResult($result['allOk'] ? self::RES_SUCCESS : self::RES_FAILURE);

        return $result['allOk'];
    }

    /**
     * @return list<string>|false
     */
    #[\Override]
    protected function doGetAllKeys(): array|false
    {
        $this->flushNetworkWrites();
        if (!$this->ensureServersAvailable()) {
            return false;
        }

        $core = $this->core();
        $result = $this->collectFromServers(static function (int $i) use ($core): ?array {
            $k = TextProtocolClient::getAllKeys($core->conn->get($i));

            return \is_array($k) ? $k : null;
        }, []);

        if (!$result['allOk'] && !$result['anyOk']) {
            $this->setResult(self::RES_FAILURE);

            return false;
        }

        $this->setResult($result['allOk'] ? self::RES_SUCCESS : self::RES_SOME_ERRORS);

        $merged = [];
        foreach ($result['values'] as $list) {
            $merged = array_merge($merged, $list);
        }

        return array_values(array_unique($merged));
    }

    // -----------------------------------------------------------------------
    // Internals: encoding, reading, error mapping
    // -----------------------------------------------------------------------

    private function prepareStoreCommand(string $key, mixed $value, int $expiration, StoreMode $mode, ?string $serverKey, ?string $casToken): string|false
    {
        $encoded = $this->encodeForStore($value);
        if (null === $encoded) {
            return false;
        }

        [$payload, $flags] = $encoded;

        $pk = $this->prefixedKey($key);
        if (!$this->checkKeyInternal($pk)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if (null !== $serverKey && !$this->checkKeyInternal($serverKey)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        $extra = [];
        if ($this->useNoReply()) {
            $extra[] = 'q';
        }

        if (null !== $casToken) {
            $extra[] = 'C'.$casToken;
        }

        return MetaCommandBuilder::metaStore($pk, $payload, $flags, $this->ttlToken($expiration), $mode->value, $extra);
    }

    private function mapStoreResult(MetaResult $r): bool
    {
        if ($this->applyMetaWireError($r)) {
            return false;
        }

        return match ($r->code) {
            'HD' => $this->okResult(self::RES_SUCCESS),
            'NS' => $this->failResult(self::RES_NOTSTORED),
            'EX' => $this->failResult(self::RES_DATA_EXISTS),
            'NF' => $this->failResult(self::RES_NOTFOUND),
            default => $this->failResult(self::RES_FAILURE),
        };
    }

    /**
     * Pure-check used by the pipelined {@see doStoreMulti()} reader. Returns
     * {@code true} iff the meta-set reply indicates the value was stored
     * ({@code HD}), without touching {@code resultCode} — the multi-store
     * caller decides the final coarse PECL code ({@code RES_SUCCESS} /
     * {@code RES_SOME_ERRORS} / {@code RES_FAILURE}) once every shard has been
     * drained. Wire-level protocol errors (CLIENT_ERROR / SERVER_ERROR /
     * ERROR) are treated as a per-item failure but again do not pollute the
     * global state code.
     */
    private function primaryStoreReplyOk(MetaResult $result): bool
    {
        if (null !== $result->wireErrorResultCode()) {
            return false;
        }

        return 'HD' === $result->code;
    }

    private function applyMetaWireError(MetaResult $r): bool
    {
        $code = $r->wireErrorResultCode();
        if (null === $code) {
            return false;
        }

        $this->setResult($code, $r->errorMessage);

        return true;
    }

    private function metaReader(StreamConnection $c): MetaReader
    {
        return new MetaReader(
            $c,
            ItemSizeGuard::effectiveReadLimit($this->optionInt(self::OPT_ITEM_SIZE_LIMIT, 0)),
        );
    }

    private function readDecodedMetaValue(MetaReader $reader): DecodedMetaValue|false
    {
        $decoded = MetaValueReader::read(
            $reader,
            $this->optionInt(self::OPT_SERIALIZER, self::SERIALIZER_PHP),
            $this->optionBool(self::OPT_ALLOW_SERIALIZED_CLASSES, false),
            $this->encodingContext(),
        );
        if ($decoded->isFailure()) {
            $this->setResult($decoded->errorCode ?? self::RES_FAILURE, $decoded->errorMessage);

            return false;
        }

        return $decoded;
    }

    private function entryFromMeta(DecodedMetaValue $item): CacheEntry
    {
        $result = $item->result();
        $flagsRaw = (int) ($result->getToken('f') ?? '0');

        return new CacheEntry(
            $item->value,
            $this->casValue($result->getCas()),
            ValueCodec::getUserFlags($flagsRaw),
        );
    }

    private function ttlToken(int $expiration): string
    {
        if ($expiration <= 0) {
            return '0';
        }

        return (string) $expiration;
    }

    private function send(StreamConnection $c, string $data): void
    {
        if ($this->optionBool(self::OPT_BUFFER_WRITES, false)) {
            $c->bufferWrite($data);
        } else {
            $c->write($data);
        }
    }
}
