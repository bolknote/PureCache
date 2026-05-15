<?php

declare(strict_types=1);

namespace PureCache\Ignite;

use PureCache\Ignite\Internal\IgniteCacheCodec;
use PureCache\Ignite\Internal\IgniteHashCode;
use PureCache\Ignite\Internal\IgniteProtocol;
use PureCache\Ignite\Internal\IgniteReply;
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
 * echoes in the header. A single transport-level retry (reconnect + resend) is
 * attempted when the TCP session fails mid-flight before a complete reply frame
 * is parsed.
 */
final class NativeIgniteClient
{
    private const string CLUSTER_VERSION_SQL = 'SELECT VERSION FROM "SYS"."NODES" LIMIT 1';

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

        $stream = stream_socket_client($remote, $errno, $errstr, $timeout, \STREAM_CLIENT_CONNECT, $context);
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
        if (\is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
        $this->nextRequestId = 1;
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

        [$value] = IgniteReply::readByteArrayObject($response, 0);

        return $value;
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

        IgniteReply::requireSpan($response, 0, 4);
        $count = IgniteWire::unpackInt32($response, 0);
        $offset = 4;
        $out = [];
        for ($i = 0; $i < $count; ++$i) {
            [$key, $offset] = IgniteReply::readStringObject($response, $offset);
            [$value, $offset] = IgniteReply::readByteArrayObject($response, $offset);
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

        return IgniteReply::readBool($response, 0);
    }

    public function cacheReplace(int $cacheId, string $key, string $value): bool
    {
        $body = $this->cacheKeyBody($cacheId, $key).IgniteCacheCodec::encodeByteArrayObject($value);
        $response = $this->execute(IgniteProtocol::OP_CACHE_REPLACE, $body);

        return IgniteReply::readBool($response, 0);
    }

    public function cacheReplaceIfEquals(int $cacheId, string $key, string $expected, string $newValue): bool
    {
        $body = $this->cacheKeyBody($cacheId, $key)
            .IgniteCacheCodec::encodeByteArrayObject($expected)
            .IgniteCacheCodec::encodeByteArrayObject($newValue);
        $response = $this->execute(IgniteProtocol::OP_CACHE_REPLACE_IF_EQUALS, $body);

        return IgniteReply::readBool($response, 0);
    }

    public function cacheRemoveKey(int $cacheId, string $key): bool
    {
        $body = $this->cacheKeyBody($cacheId, $key);
        $response = $this->execute(IgniteProtocol::OP_CACHE_REMOVE_KEY, $body);

        return IgniteReply::readBool($response, 0);
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

        IgniteReply::requireSpan($response, 0, 8);

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
        IgniteReply::requireSpan($response, 0, 8);
        $cursorId = IgniteWire::unpackInt64($response, 0);

        try {
            $keys = [];
            [$page, $hasMore] = IgniteReply::readScanPage($response, 8);
            array_push($keys, ...$page);

            while ($hasMore) {
                $pageBody = IgniteWire::packInt64($cursorId);
                $pageResponse = $this->execute(IgniteProtocol::OP_QUERY_SCAN_CURSOR_GET_PAGE, $pageBody);
                [$nextPage, $hasMore] = IgniteReply::readScanPage($pageResponse, 0);
                array_push($keys, ...$nextPage);
            }

            return $keys;
        } finally {
            if (0 !== $cursorId) {
                $this->closeResourceQuietly($cursorId);
            }
        }
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
        IgniteReply::requireSpan($response, 0, 1);
        $status = IgniteWire::unpackUint8($response, 0);
        if (IgniteProtocol::HANDSHAKE_OK !== $status) {
            $major = IgniteWire::unpackInt16($response, 1);
            $minor = IgniteWire::unpackInt16($response, 3);
            $patch = IgniteWire::unpackInt16($response, 5);
            [$message] = IgniteReply::readStringObject($response, 7);

            throw new \RuntimeException(\sprintf('Ignite handshake failed (server %d.%d.%d): %s', $major, $minor, $patch, $message));
        }

        $this->nextRequestId = 1;
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
            return $this->parseSqlFieldsVersion($response);
        } finally {
            IgniteReply::requireSpan($response, 0, 8);
            $cursorId = IgniteWire::unpackInt64($response, 0);
            if (0 !== $cursorId) {
                $this->closeResourceQuietly($cursorId);
            }
        }
    }

    private function closeResource(int $resourceId): void
    {
        $this->execute(IgniteProtocol::OP_RESOURCE_CLOSE, IgniteWire::packInt64($resourceId));
    }

    private function closeResourceQuietly(int $resourceId): void
    {
        try {
            $this->closeResource($resourceId);
        } catch (\Throwable) {
            // cursor cleanup must not mask the original error path
        }
    }

    /**
     * Reads the VERSION cell from the first row of an {@code OP_QUERY_SQL_FIELDS}
     * reply (column 0). The SQL uses {@code LIMIT 1} so the cluster version is
     * unambiguous even when several nodes are registered.
     */
    private function parseSqlFieldsVersion(string $response): string
    {
        if (\strlen($response) < 16) {
            throw new \RuntimeException('Ignite SQL fields reply too short');
        }

        IgniteReply::requireSpan($response, 8, 8);
        $columnCount = IgniteWire::unpackInt32($response, 8);
        $offset = 12;
        $rowCount = IgniteWire::unpackInt32($response, $offset);
        $offset += 4;

        if ($rowCount < 1 || $columnCount < 1) {
            throw new \RuntimeException('Ignite SQL fields query returned no VERSION row');
        }

        [$value] = IgniteReply::readDataObject($response, $offset);
        if (null === $value || '' === (string) $value) {
            throw new \RuntimeException('Ignite SQL fields query returned empty VERSION');
        }

        return (string) $value;
    }

    private function execute(int $opCode, string $body): string
    {
        try {
            return $this->executeOnce($opCode, $body);
        } catch (\RuntimeException $exception) {
            if (!$this->isRetriableTransportFailure($exception)) {
                throw $exception;
            }

            $this->reconnectTransport();

            return $this->executeOnce($opCode, $body);
        }
    }

    private function executeOnce(int $opCode, string $body): string
    {
        $this->ensureConnected();
        $this->opCounts[$opCode] = ($this->opCounts[$opCode] ?? 0) + 1;
        $requestId = $this->nextRequestId++;
        $message = IgniteWire::packInt16($opCode).IgniteWire::packInt64($requestId).$body;
        $this->writeBytes(IgniteWire::packInt32(\strlen($message)).$message);

        return $this->readResponse($requestId);
    }

    private function isRetriableTransportFailure(\RuntimeException $exception): bool
    {
        $message = $exception->getMessage();

        return \in_array($message, [
            'Ignite read truncated',
            'Ignite read timed out',
            'Ignite write failed',
            'Ignite not connected',
        ], true) || str_starts_with($message, 'Ignite reply: frame length ');
    }

    private function reconnectTransport(): void
    {
        $this->disconnect();
        $this->connect();
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
            [$message] = IgniteReply::readStringObject($frame, 12);

            throw new IgniteCommandException($message, $status);
        }

        return substr($frame, 12);
    }

    private function readFramedResponse(): string
    {
        $header = $this->readExact(4);
        $length = IgniteWire::unpackInt32($header, 0);
        IgniteReply::assertFrameLength($length);

        if (0 === $length) {
            return '';
        }

        return $this->readExact($length);
    }

    private function packBool(bool $value): string
    {
        return IgniteWire::packInt8($value ? 1 : 0);
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
            $chunk = fwrite($this->stream, substr($data, $written));
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
        while (($remaining = $length - \strlen($buf)) > 0) {
            $chunk = fread($this->stream, $remaining);
            if (false === $chunk || '' === $chunk) {
                $this->checkTimeout();
                throw new \RuntimeException('Ignite read truncated');
            }

            $buf .= $chunk;
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
