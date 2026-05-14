<?php

declare(strict_types=1);

namespace PureCache\Memcached;

use PureCache\AbstractCacheClient;
use PureCache\Internal\CacheEntry;
use PureCache\Internal\EncodingContext;
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
     * Memcached's connection manager handles write buffering for the meta protocol.
     */
    #[\Override]
    protected function flushNetworkWrites(): void
    {
        try {
            $this->core()->conn->flushAllBuffers();
        } catch (\Throwable $throwable) {
            $this->recordServerFailure(null, $throwable);
            throw $throwable;
        }
    }

    private function core(): MemcachedClientCore
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
            $c = $this->core()->conn->get($idx);
            $c->write(MetaCommandBuilder::metaGetValue($pk));

            $reader = new MetaReader($c);
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

        $found = [];
        $hadFailure = false;
        $hadSuccess = false;
        $lastError = null;
        foreach ($byServer as $serverIdx => $pairs) {
            try {
                $c = $core->conn->get($serverIdx);
                foreach ($pairs as [, $pk]) {
                    $this->send($c, MetaCommandBuilder::metaGetValue($pk));
                }

                $reader = new MetaReader($c);
                foreach ($pairs as [$orig]) {
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
                foreach ($pairs as [, $pk]) {
                    $this->send($c, MetaCommandBuilder::metaGetValue($pk));
                }

                $reader = new MetaReader($c);
                foreach ($pairs as [$orig]) {
                    $item = $this->readDecodedMetaValue($reader);
                    if (false === $item) {
                        return false;
                    }

                    if ($item->found) {
                        $results[] = $this->delayedEntry($orig, $this->entryFromMeta($item), $withCas);
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
                foreach ($pairs as [, $pk]) {
                    $this->send($c, MetaCommandBuilder::metaGetValue($pk));
                }

                $reader = new MetaReader($c);
                foreach ($pairs as [$orig]) {
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
        $this->pristine = false;
        if ($mode->isConcatenation() && $this->optionBool(self::OPT_COMPRESSION, true)) {
            trigger_error('cannot append/prepend with compression turned on', \E_USER_WARNING);
            $this->setResult(self::RES_NOTSTORED);

            return false;
        }

        if ($mode->isConcatenation() && $this->encodingContext() instanceof EncodingContext) {
            trigger_error('cannot append/prepend with encoding key set', \E_USER_WARNING);
            $this->setResult(self::RES_NOTSTORED);

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

                $reader = new MetaReader($c);
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

        $core = $this->core();
        $batches = [];
        $ok = true;
        foreach ($items as $key => $value) {
            $keyString = (string) $key;
            $prepared = $this->prepareStoreCommand($keyString, $value, $expiration, StoreMode::Set, $serverKey, null);
            if (false === $prepared) {
                $ok = false;
                continue;
            }

            $idx = $this->pickServerIndex($serverKey, $keyString);
            $batches[$idx][] = $prepared;
        }

        $currentIdx = null;
        try {
            if (!$this->shouldBufferNoReplyWrite()) {
                $this->flushNetworkWrites();
            }

            foreach ($batches as $serverIdx => $commands) {
                $currentIdx = $serverIdx;
                $c = $core->conn->get($serverIdx);
                foreach ($commands as $cmd) {
                    $this->send($c, $cmd);
                }

                if ($this->useNoReply()) {
                    continue;
                }

                $reader = new MetaReader($c);
                foreach ($commands as $_) {
                    if (!$this->storeSucceeded($reader->readOne(false))) {
                        $ok = false;
                    }
                }
            }
        } catch (\Throwable $throwable) {
            $this->recordServerFailure($currentIdx, $throwable);
            $this->setResult(self::RES_FAILURE, $throwable->getMessage());

            return false;
        }

        $this->setResult($ok ? self::RES_SUCCESS : self::RES_SOME_ERRORS);

        return $ok;
    }

    #[\Override]
    protected function doTouch(string $key, int $expiration, ?string $serverKey): bool
    {
        $this->flushNetworkWrites();

        return $this->executeKeyed($key, $serverKey, function (int $idx, string $pk) use ($expiration): bool {
            $c = $this->core()->conn->get($idx);
            $c->write(MetaCommandBuilder::metaGetTouch($pk, $this->ttlToken($expiration)));

            $reader = new MetaReader($c);
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
        // PECL parity: `delete('bad key', 1)` reports RES_BAD_KEY_PROVIDED, but
        // `delete('valid', 1)` with no servers configured still reports
        // RES_NOT_SUPPORTED — i.e. key validation > delete-time validation > server pool.
        // {@see executeKeyed()} would short-circuit on the empty pool first, so
        // this method spells the order out manually.
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
            $this->flushNetworkWrites();
        }

        return $this->writeFanout($serverKey, $key, function (int $idx) use ($pk): bool {
            try {
                $c = $this->core()->conn->get($idx);
                $this->send($c, MetaCommandBuilder::metaDelete($pk, $this->useNoReply()));
                if ($this->useNoReply()) {
                    $this->setResult(self::RES_SUCCESS);

                    return true;
                }

                $reader = new MetaReader($c);
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
            $c->write(MetaCommandBuilder::metaArith(
                $pk,
                $offset,
                $decrement,
                $autoCreate ? $initialValue : null,
                $autoCreate ? $expiry : null,
            ));
            $reader = new MetaReader($c);
            $r = $reader->readArithmeticValue();
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
        $core = $this->core();
        if ([] === $core->selector->getServers()) {
            $this->setResult(self::RES_NO_SERVERS);

            return false;
        }

        $out = [];
        $ok = true;
        foreach ($core->selector->getServers() as $i => $s) {
            $label = $s['host'].':'.$s['port'];
            try {
                $c = $core->conn->get($i);
                $st = TextProtocolClient::stats($c, $type);
                if (false === $st) {
                    $ok = false;
                }

                $out[$label] = $st;
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
        $this->flushNetworkWrites();
        $core = $this->core();
        if ([] === $core->selector->getServers()) {
            $this->setResult(self::RES_NO_SERVERS);

            return false;
        }

        $out = [];
        $ok = true;
        foreach ($core->selector->getServers() as $i => $s) {
            $label = $s['host'].':'.$s['port'];
            try {
                $c = $core->conn->get($i);
                $v = TextProtocolClient::version($c);
                if (false === $v) {
                    $ok = false;
                }

                $out[$label] = false === $v ? '' : $v;
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
        $this->flushNetworkWrites();
        $core = $this->core();
        if ([] === $core->selector->getServers()) {
            $this->setResult(self::RES_NO_SERVERS);

            return false;
        }

        $ok = true;
        foreach (array_keys($core->selector->getServers()) as $i) {
            try {
                $c = $core->conn->get($i);
                if (!TextProtocolClient::flushAll($c, $delay)) {
                    $ok = false;
                }
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
        $this->flushNetworkWrites();
        $core = $this->core();
        if ([] === $core->selector->getServers()) {
            $this->setResult(self::RES_NO_SERVERS);

            return false;
        }

        $keys = [];
        $ok = true;
        $hadSuccess = false;
        foreach (array_keys($core->selector->getServers()) as $i) {
            try {
                $c = $core->conn->get($i);
                $k = TextProtocolClient::getAllKeys($c);
                if (\is_array($k)) {
                    $hadSuccess = true;
                    $keys = array_merge($keys, $k);
                } else {
                    $ok = false;
                }
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
    // Internals: encoding, reading, error mapping
    // -----------------------------------------------------------------------

    private function prepareStoreCommand(string $key, mixed $value, int $expiration, StoreMode $mode, ?string $serverKey, ?string $casToken): string|false
    {
        $core = $this->core();
        try {
            [$payload, $flags] = ValueCodec::encode(
                $value,
                $this->optionInt(self::OPT_SERIALIZER, self::SERIALIZER_PHP),
                $this->optionBool(self::OPT_COMPRESSION, true),
                $this->optionInt(self::OPT_COMPRESSION_TYPE, self::COMPRESSION_TYPE_FASTLZ),
                $this->optionInt(self::OPT_COMPRESSION_LEVEL, 3),
                $core->compressionThreshold,
                $core->compressionFactor,
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

    private function storeSucceeded(MetaResult $result): bool
    {
        if ($this->applyMetaWireError($result)) {
            return false;
        }

        return match ($result->code) {
            'HD' => true,
            'NS' => $this->failResult(self::RES_NOTSTORED),
            'EX' => $this->failResult(self::RES_DATA_EXISTS),
            'NF' => $this->failResult(self::RES_NOTFOUND),
            default => $this->failResult(self::RES_FAILURE),
        };
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

    private function okResult(int $code): bool
    {
        $this->setResult($code);

        return true;
    }

    private function failResult(int $code): bool
    {
        $this->setResult($code);

        return false;
    }
}
