<?php

declare(strict_types=1);

namespace PureCache\Ignite;

use PureCache\Ignite\Internal\IgniteCacheCodec;
use PureCache\Ignite\Internal\IgniteHashCode;
use PureCache\Ignite\Internal\IgniteProtocol;
use PureCache\Ignite\Internal\IgniteStatsSnapshot;
use PureCache\Ignite\Internal\IgniteWire;

/**
 * Pure-PHP Ignite thin-client transport.
 *
 * Speaks the v1.2.0 binary client protocol directly over a TCP stream — no
 * external Ignite PHP package is pulled in. Only the request/response shapes
 * exercised by {@see IgniteClient} are implemented (plus {@code OP_QUERY_SQL_FIELDS}
 * for cluster version discovery). No binary type registration, so the surface
 * area stays small and reviewable.
 *
 * Each instance multiplexes requests onto a single socket and pairs them with
 * server replies by the auto-incrementing {@code requestId} that the protocol
 * echoes in the header.
 */
final class NativeIgniteClient
{
    private const string CLUSTER_VERSION_SQL = 'SELECT VERSION FROM "SYS"."NODES"';

    private const string CLUSTER_VERSION_SCHEMA = 'SYS';

    /** @var resource|null */
    private $stream;

    private int $nextRequestId = 1;

    private string $serverVersion = '';

    /**
     * Unix timestamp of the most recent successful handshake; 0 when the
     * client has not connected yet (or after {@see disconnect()}).
     */
    private int $connectedAt = 0;

    private int $bytesRead = 0;

    private int $bytesWritten = 0;

    /** @var array<int, int> opcode → call count since the last connect */
    private array $opCounts = [];

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $readWriteTimeout = 0.0,
    ) {
    }

    public function connect(): void
    {
        if (\is_resource($this->stream)) {
            return;
        }

        $errno = 0;
        $errstr = '';
        $remote = 'tcp://'.$this->host.':'.$this->port;
        $timeout = $this->readWriteTimeout > 0 ? $this->readWriteTimeout : (float) \ini_get('default_socket_timeout');

        $context = stream_context_create([
            'socket' => ['tcp_nodelay' => true],
        ]);

        $stream = @stream_socket_client($remote, $errno, $errstr, $timeout, \STREAM_CLIENT_CONNECT, $context);
        if (!\is_resource($stream)) {
            throw new \RuntimeException('Ignite connect failed: '.$errstr.' ('.$errno.')');
        }

        stream_set_blocking($stream, true);
        if ($this->readWriteTimeout > 0) {
            $sec = (int) floor($this->readWriteTimeout);
            $usec = (int) round(($this->readWriteTimeout - $sec) * 1_000_000);
            stream_set_timeout($stream, $sec, $usec);
        }

        $this->stream = $stream;

        try {
            $this->performHandshake();
        } catch (\Throwable $throwable) {
            $this->disconnect();
            throw $throwable;
        }
    }

    public function disconnect(): void
    {
        if (!\is_resource($this->stream)) {
            return;
        }

        fclose($this->stream);
        $this->stream = null;
        $this->connectedAt = 0;
        $this->serverVersion = '';
        $this->bytesRead = 0;
        $this->bytesWritten = 0;
        $this->opCounts = [];
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Resolves the Ignite cluster product version via {@code SYS.NODES} and caches
     * it for {@see getStatsSnapshot()}. Requires the named cache to exist on the
     * node (call after {@code cacheGetOrCreate}).
     */
    public function resolveProductVersion(int $cacheId): string
    {
        if ('' !== $this->serverVersion) {
            return $this->serverVersion;
        }

        $this->serverVersion = $this->queryClusterVersion($cacheId);

        return $this->serverVersion;
    }

    /**
     * Snapshot of bytes/opcode/uptime counters maintained by this connection.
     * Used by {@see IgniteStatsAsMemcached} to build a
     * memcached-shaped {@code stats} reply.
     */
    public function getStatsSnapshot(): IgniteStatsSnapshot
    {
        return new IgniteStatsSnapshot(
            $this->serverVersion,
            $this->connectedAt,
            $this->bytesRead,
            $this->bytesWritten,
            $this->opCounts,
        );
    }

    // -----------------------------------------------------------------------
    // Cache operations
    // -----------------------------------------------------------------------

    public function getOrCreateCache(string $name): int
    {
        $this->ensureConnected();
        $cacheId = IgniteHashCode::ofString($name);
        $body = IgniteCacheCodec::encodeStringObject($name);
        $this->execute(IgniteProtocol::OP_CACHE_GET_OR_CREATE_WITH_NAME, $body);

        return $cacheId;
    }

    public function cacheGet(int $cacheId, string $key): ?string
    {
        $body = $this->cacheKeyBody($cacheId, $key);
        $response = $this->execute(IgniteProtocol::OP_CACHE_GET, $body);

        return $this->readByteArrayObject($response, 0);
    }

    /**
     * Batched {@code OP_CACHE_GET_ALL}. Sends every requested key in a single
     * request frame so multi-get round-trips collapse to one read per shard
     * instead of one per key. Missing keys are silently dropped from the
     * returned map — callers should iterate over the original key list and
     * check {@code isset($result[$key])} to detect misses.
     *
     * @param list<string> $keys
     *
     * @return array<string, string> map from key → raw byte[] payload
     */
    public function cacheGetAll(int $cacheId, array $keys): array
    {
        if ([] === $keys) {
            return [];
        }

        $body = $this->cacheInfoBody($cacheId).IgniteWire::packInt32(\count($keys));
        foreach ($keys as $key) {
            $body .= IgniteCacheCodec::encodeStringObject($key);
        }

        $response = $this->execute(IgniteProtocol::OP_CACHE_GET_ALL, $body);

        $count = IgniteWire::unpackInt32($response, 0);
        $offset = 4;
        $out = [];
        for ($i = 0; $i < $count; ++$i) {
            [$key, $offset] = $this->readStringObject($response, $offset);
            $value = $this->readByteArrayObject($response, $offset);
            $offset = $this->skipByteArrayObject($response, $offset);
            if (null !== $value) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public function cachePut(int $cacheId, string $key, string $value): void
    {
        $body = $this->cacheKeyBody($cacheId, $key).IgniteCacheCodec::encodeByteArrayObject($value);
        $this->execute(IgniteProtocol::OP_CACHE_PUT, $body);
    }

    public function cachePutIfAbsent(int $cacheId, string $key, string $value): bool
    {
        $body = $this->cacheKeyBody($cacheId, $key).IgniteCacheCodec::encodeByteArrayObject($value);
        $response = $this->execute(IgniteProtocol::OP_CACHE_PUT_IF_ABSENT, $body);

        return $this->readBool($response, 0);
    }

    public function cacheReplace(int $cacheId, string $key, string $value): bool
    {
        $body = $this->cacheKeyBody($cacheId, $key).IgniteCacheCodec::encodeByteArrayObject($value);
        $response = $this->execute(IgniteProtocol::OP_CACHE_REPLACE, $body);

        return $this->readBool($response, 0);
    }

    public function cacheReplaceIfEquals(int $cacheId, string $key, string $expected, string $newValue): bool
    {
        $body = $this->cacheKeyBody($cacheId, $key)
            .IgniteCacheCodec::encodeByteArrayObject($expected)
            .IgniteCacheCodec::encodeByteArrayObject($newValue);
        $response = $this->execute(IgniteProtocol::OP_CACHE_REPLACE_IF_EQUALS, $body);

        return $this->readBool($response, 0);
    }

    public function cacheRemoveKey(int $cacheId, string $key): bool
    {
        $body = $this->cacheKeyBody($cacheId, $key);
        $response = $this->execute(IgniteProtocol::OP_CACHE_REMOVE_KEY, $body);

        return $this->readBool($response, 0);
    }

    public function cacheClear(int $cacheId): void
    {
        $body = $this->cacheInfoBody($cacheId);
        $this->execute(IgniteProtocol::OP_CACHE_CLEAR, $body);
    }

    public function cacheGetSize(int $cacheId): int
    {
        $body = $this->cacheInfoBody($cacheId);
        $body .= IgniteWire::packInt32(0);
        $response = $this->execute(IgniteProtocol::OP_CACHE_GET_SIZE, $body);

        return IgniteWire::unpackInt64($response, 0);
    }

    /**
     * @return list<string>
     */
    public function cacheScanKeys(int $cacheId, int $pageSize = 256): array
    {
        $body = $this->cacheInfoBody($cacheId);
        $body .= IgniteCacheCodec::encodeNullObject();
        $body .= IgniteWire::packInt32($pageSize);
        $body .= IgniteWire::packInt32(-1);
        $body .= IgniteWire::packInt8(0);

        $response = $this->execute(IgniteProtocol::OP_QUERY_SCAN, $body);
        $cursorId = IgniteWire::unpackInt64($response, 0);

        $keys = [];
        [$page, $hasMore] = $this->readScanPage($response, 8);
        foreach ($page as $key) {
            $keys[] = $key;
        }

        while ($hasMore) {
            $pageBody = IgniteWire::packInt64($cursorId);
            $pageResponse = $this->execute(IgniteProtocol::OP_QUERY_SCAN_CURSOR_GET_PAGE, $pageBody);
            [$nextPage, $hasMore] = $this->readScanPage($pageResponse, 0);
            foreach ($nextPage as $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function performHandshake(): void
    {
        $body = IgniteWire::packInt8(IgniteProtocol::HANDSHAKE_CODE)
            .IgniteWire::packInt16(IgniteProtocol::PROTOCOL_MAJOR)
            .IgniteWire::packInt16(IgniteProtocol::PROTOCOL_MINOR)
            .IgniteWire::packInt16(IgniteProtocol::PROTOCOL_PATCH)
            .IgniteWire::packInt8(IgniteProtocol::CLIENT_TYPE_THIN);

        $this->writeBytes(IgniteWire::packInt32(\strlen($body)).$body);

        $response = $this->readFramedResponse();
        $status = IgniteWire::unpackUint8($response, 0);
        if (IgniteProtocol::HANDSHAKE_OK !== $status) {
            $major = IgniteWire::unpackInt16($response, 1);
            $minor = IgniteWire::unpackInt16($response, 3);
            $patch = IgniteWire::unpackInt16($response, 5);
            [$message] = $this->readStringObject($response, 7);

            throw new \RuntimeException(\sprintf('Ignite handshake failed (server %d.%d.%d): %s', $major, $minor, $patch, $message));
        }

        $this->connectedAt = time();
    }

    private function queryClusterVersion(int $cacheId): string
    {
        $body = $this->cacheInfoBody($cacheId)
            .IgniteCacheCodec::encodeStringObject(self::CLUSTER_VERSION_SCHEMA)
            .IgniteWire::packInt32(1)
            .IgniteWire::packInt32(1)
            .IgniteCacheCodec::encodeStringObject(self::CLUSTER_VERSION_SQL)
            .IgniteWire::packInt32(0)
            .IgniteWire::packInt8(IgniteProtocol::SQL_STATEMENT_SELECT)
            .$this->packBool(false)
            .$this->packBool(false)
            .$this->packBool(false)
            .$this->packBool(false)
            .$this->packBool(false)
            .$this->packBool(false)
            .IgniteWire::packInt64(5_000)
            .$this->packBool(false);

        $response = $this->execute(IgniteProtocol::OP_QUERY_SQL_FIELDS, $body);

        try {
            return $this->parseSqlFieldsFirstCell($response);
        } finally {
            $cursorId = IgniteWire::unpackInt64($response, 0);
            if (0 !== $cursorId) {
                $this->closeResource($cursorId);
            }
        }
    }

    private function closeResource(int $resourceId): void
    {
        $this->execute(IgniteProtocol::OP_RESOURCE_CLOSE, IgniteWire::packInt64($resourceId));
    }

    private function parseSqlFieldsFirstCell(string $response): string
    {
        if (\strlen($response) < 16) {
            throw new \RuntimeException('Ignite SQL fields reply too short');
        }

        $columnCount = IgniteWire::unpackInt32($response, 8);
        $offset = 12;
        $rowCount = IgniteWire::unpackInt32($response, $offset);
        $offset += 4;

        for ($row = 0; $row < $rowCount; ++$row) {
            for ($col = 0; $col < $columnCount; ++$col) {
                [$value, $offset] = $this->readDataObject($response, $offset);
                if (null !== $value && '' !== (string) $value) {
                    return (string) $value;
                }
            }
        }

        throw new \RuntimeException('Ignite SQL fields query returned no VERSION value');
    }

    private function execute(int $opCode, string $body): string
    {
        $this->ensureConnected();
        $this->opCounts[$opCode] = ($this->opCounts[$opCode] ?? 0) + 1;
        $requestId = $this->nextRequestId++;
        $message = IgniteWire::packInt16($opCode).IgniteWire::packInt64($requestId).$body;
        $this->writeBytes(IgniteWire::packInt32(\strlen($message)).$message);

        return $this->readResponse($requestId);
    }

    private function readResponse(int $expectedRequestId): string
    {
        $frame = $this->readFramedResponse();
        if (\strlen($frame) < 12) {
            throw new \RuntimeException('Ignite reply too short');
        }

        $responseId = IgniteWire::unpackInt64($frame, 0);
        if ($responseId !== $expectedRequestId) {
            throw new \RuntimeException('Ignite reply request id mismatch');
        }

        $status = IgniteWire::unpackInt32($frame, 8);
        if (IgniteProtocol::RESPONSE_OK !== $status) {
            [$message] = $this->readStringObject($frame, 12);

            throw new IgniteCommandException($message, $status);
        }

        return substr($frame, 12);
    }

    private function readFramedResponse(): string
    {
        $header = $this->readExact(4);
        $length = IgniteWire::unpackInt32($header, 0);
        if ($length < 0) {
            throw new \RuntimeException('Ignite reply: invalid frame length');
        }

        if (0 === $length) {
            return '';
        }

        return $this->readExact($length);
    }

    /**
     * @return array{0:list<string>, 1:bool}
     */
    private function readScanPage(string $bytes, int $offset): array
    {
        $rowCount = IgniteWire::unpackInt32($bytes, $offset);
        $offset += 4;
        $keys = [];
        for ($i = 0; $i < $rowCount; ++$i) {
            [$key, $offset] = $this->readStringObject($bytes, $offset);
            $keys[] = $key;
            $offset = $this->skipByteArrayObject($bytes, $offset);
        }

        return [$keys, $this->readBool($bytes, $offset)];
    }

    /**
     * Reads one type-prefixed {@code byte[]} (type 12) or NULL (type 101) at the
     * given offset. Returns {@code null} when the object is NULL so callers can
     * disambiguate "missing key" from "empty byte array".
     */
    private function readByteArrayObject(string $bytes, int $offset): ?string
    {
        $type = IgniteWire::unpackUint8($bytes, $offset);
        if (IgniteProtocol::TYPE_NULL === $type) {
            return null;
        }

        if (IgniteProtocol::TYPE_BYTE_ARRAY !== $type) {
            throw new \RuntimeException('Ignite reply: expected byte_array, got type '.$type);
        }

        $length = IgniteWire::unpackInt32($bytes, $offset + 1);
        if ($length < 0) {
            throw new \RuntimeException('Ignite reply: negative byte_array length');
        }

        return substr($bytes, $offset + 5, $length);
    }

    private function skipByteArrayObject(string $bytes, int $offset): int
    {
        $type = IgniteWire::unpackUint8($bytes, $offset);
        if (IgniteProtocol::TYPE_NULL === $type) {
            return $offset + 1;
        }

        if (IgniteProtocol::TYPE_BYTE_ARRAY !== $type) {
            throw new \RuntimeException('Ignite reply: expected byte_array, got type '.$type);
        }

        $length = IgniteWire::unpackInt32($bytes, $offset + 1);

        return $offset + 5 + max(0, $length);
    }

    /**
     * Reads one type-prefixed {@code String} object and returns the decoded
     * value along with the next read offset.
     *
     * @return array{0:string,1:int}
     */
    /**
     * @return array{0:string|int|null,1:int}
     */
    private function readDataObject(string $bytes, int $offset): array
    {
        $type = IgniteWire::unpackUint8($bytes, $offset);
        if (IgniteProtocol::TYPE_NULL === $type) {
            return [null, $offset + 1];
        }

        if (IgniteProtocol::TYPE_STRING === $type) {
            return $this->readStringObject($bytes, $offset);
        }

        if (IgniteProtocol::TYPE_INT === $type) {
            $value = IgniteWire::unpackInt32($bytes, $offset + 1);

            return [$value, $offset + 5];
        }

        if (IgniteProtocol::TYPE_LONG === $type) {
            $value = IgniteWire::unpackInt64($bytes, $offset + 1);

            return [$value, $offset + 9];
        }

        if (IgniteProtocol::TYPE_BYTE_ARRAY === $type) {
            $length = IgniteWire::unpackInt32($bytes, $offset + 1);
            if ($length < 0) {
                throw new \RuntimeException('Ignite reply: negative byte_array length');
            }

            return [substr($bytes, $offset + 5, $length), $offset + 5 + $length];
        }

        throw new \RuntimeException('Ignite reply: unsupported data object type '.$type);
    }

    private function packBool(bool $value): string
    {
        return IgniteWire::packInt8($value ? 1 : 0);
    }

    /**
     * @return array{0:string,1:int}
     */
    private function readStringObject(string $bytes, int $offset): array
    {
        $type = IgniteWire::unpackUint8($bytes, $offset);
        if (IgniteProtocol::TYPE_NULL === $type) {
            return ['', $offset + 1];
        }

        if (IgniteProtocol::TYPE_STRING !== $type) {
            throw new \RuntimeException('Ignite reply: expected string, got type '.$type);
        }

        $length = IgniteWire::unpackInt32($bytes, $offset + 1);
        if ($length < 0) {
            throw new \RuntimeException('Ignite reply: negative string length');
        }

        return [substr($bytes, $offset + 5, $length), $offset + 5 + $length];
    }

    private function readBool(string $bytes, int $offset): bool
    {
        return 1 === IgniteWire::unpackUint8($bytes, $offset);
    }

    private function cacheInfoBody(int $cacheId): string
    {
        return IgniteWire::packInt32($cacheId).IgniteWire::packInt8(0);
    }

    private function cacheKeyBody(int $cacheId, string $key): string
    {
        return $this->cacheInfoBody($cacheId).IgniteCacheCodec::encodeStringObject($key);
    }

    private function ensureConnected(): void
    {
        if (!\is_resource($this->stream)) {
            $this->connect();
        }
    }

    private function writeBytes(string $data): void
    {
        if (!\is_resource($this->stream)) {
            throw new \RuntimeException('Ignite not connected');
        }

        $total = \strlen($data);
        $written = 0;
        while ($written < $total) {
            $chunk = @fwrite($this->stream, substr($data, $written));
            if (false === $chunk || 0 === $chunk) {
                $this->checkTimeout();
                throw new \RuntimeException('Ignite write failed');
            }

            $written += $chunk;
        }

        $this->bytesWritten += $total;
    }

    private function readExact(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        if (!\is_resource($this->stream)) {
            throw new \RuntimeException('Ignite not connected');
        }

        $buf = '';
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = @fread($this->stream, $remaining);
            if (false === $chunk || '' === $chunk) {
                $this->checkTimeout();
                throw new \RuntimeException('Ignite read truncated');
            }

            $buf .= $chunk;
            $remaining = $length - \strlen($buf);
        }

        $this->bytesRead += $length;

        return $buf;
    }

    private function checkTimeout(): void
    {
        if (!\is_resource($this->stream)) {
            return;
        }

        $meta = stream_get_meta_data($this->stream);
        if ($meta['timed_out']) {
            throw new \RuntimeException('Ignite read timed out');
        }
    }
}
