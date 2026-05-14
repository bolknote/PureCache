<?php

declare(strict_types=1);

namespace PureCache\Redis;

/**
 * Minimal Redis TCP client (RESP2). No external Redis PHP extension — same idea as the memcached stack path.
 */
class NativeRedisClient implements RedisStatsBackend
{
    /**
     * Defensive cap on a single RESP {@code *N} array length. Real Redis
     * replies (HGETALL, MGET, SCAN) are bounded by configuration; we use this
     * to refuse pathological/garbled replies before we recurse 100M times.
     */
    public const int MAX_ARRAY_REPLY_LENGTH = 1_048_576;

    /** @var resource|null */
    private $stream;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $readWriteTimeout = 0.0,
        private readonly ?string $authUser = null,
        #[\SensitiveParameter]
        private readonly ?string $authPassword = null,
        private readonly ?int $database = null,
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
        $flags = \STREAM_CLIENT_CONNECT;
        $timeout = $this->readWriteTimeout > 0 ? $this->readWriteTimeout : (float) \ini_get('default_socket_timeout');

        $ctx = stream_context_create([
            'socket' => ['tcp_nodelay' => true],
        ]);

        $stream = @stream_socket_client($remote, $errno, $errstr, $timeout, $flags, $ctx);
        if (!\is_resource($stream)) {
            throw new \RuntimeException('Redis connect failed: '.$errstr.' ('.$errno.')');
        }

        stream_set_blocking($stream, true);
        if ($this->readWriteTimeout > 0) {
            $sec = (int) floor($this->readWriteTimeout);
            $usec = (int) round(($this->readWriteTimeout - $sec) * 1_000_000);
            stream_set_timeout($stream, $sec, $usec);
        }

        $this->stream = $stream;

        $this->performHandshake();
    }

    private function performHandshake(): void
    {
        if (null !== $this->authPassword && '' !== $this->authPassword) {
            $argv = ['AUTH'];
            if (null !== $this->authUser && '' !== $this->authUser) {
                $argv[] = $this->authUser;
            }

            $argv[] = $this->authPassword;
            $this->writeArgv($argv);
            try {
                $this->readReply();
            } catch (RedisCommandException $redisCommandException) {
                $this->forceClose();
                throw new \RuntimeException('Redis AUTH rejected: '.$redisCommandException->getMessage(), 0, $redisCommandException);
            }
        }

        if (null !== $this->database && 0 !== $this->database) {
            $this->writeArgv(['SELECT', (string) $this->database]);
            try {
                $this->readReply();
            } catch (RedisCommandException $redisCommandException) {
                $this->forceClose();
                throw new \RuntimeException('Redis SELECT '.$this->database.' rejected: '.$redisCommandException->getMessage(), 0, $redisCommandException);
            }
        }
    }

    private function forceClose(): void
    {
        if (\is_resource($this->stream)) {
            @fclose($this->stream);
        }

        $this->stream = null;
    }

    public function disconnect(): void
    {
        if (!\is_resource($this->stream)) {
            return;
        }

        try {
            $this->writeArgv(['QUIT']);
            $this->drainOneReply();
        } catch (\Throwable) {
        }

        $stream = $this->stream;
        if (\is_resource($stream)) {
            fclose($stream);
        }

        $this->stream = null;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Low-level: argv[0] is the Redis command name (uppercase recommended).
     *
     * @param list<string> $argv
     */
    public function executeRaw(array $argv): mixed
    {
        $this->ensureConnected();
        $this->writeArgv($argv);

        return $this->readReply();
    }

    /**
     * Send N RESP commands in one TCP write and read their replies back in order
     * (classic Redis pipelining). On error any exception from {@see readReply()}
     * is captured in-place so subsequent replies are still drained.
     *
     * @param list<list<string>> $commands
     *
     * @return list<mixed> reply per command; on per-reply failure the slot contains the thrown {@see \Throwable}
     */
    public function pipeline(array $commands): array
    {
        if ([] === $commands) {
            return [];
        }

        $this->ensureConnected();
        $buf = '';
        foreach ($commands as $argv) {
            $buf .= '*'.\count($argv)."\r\n";
            foreach ($argv as $a) {
                $buf .= '$'.\strlen($a)."\r\n".$a."\r\n";
            }
        }

        $this->writeAllOrThrow($buf, 'Redis pipeline write failed');

        $replies = [];
        foreach ($commands as $_) {
            try {
                $replies[] = $this->readReply();
            } catch (\Throwable $throwable) {
                $replies[] = $throwable;
            }
        }

        return $replies;
    }

    /**
     * Runs a Lua script via {@code EVALSHA}, lazily loading it with
     * {@code SCRIPT LOAD} on NOSCRIPT. This keeps the wire payload tiny while
     * remaining transparent to callers.
     *
     * @param list<string> $keys
     * @param list<string> $args
     */
    public function evalScript(string $script, array $keys, array $args): mixed
    {
        $sha = sha1($script);
        $argv = ['EVALSHA', $sha, (string) \count($keys)];
        foreach ($keys as $k) {
            $argv[] = $k;
        }

        foreach ($args as $a) {
            $argv[] = $a;
        }

        try {
            return $this->executeRaw($argv);
        } catch (RedisCommandException $redisCommandException) {
            if (!str_starts_with($redisCommandException->getMessage(), 'NOSCRIPT')) {
                throw $redisCommandException;
            }

            $this->executeRaw(['SCRIPT', 'LOAD', $script]);

            return $this->executeRaw($argv);
        }
    }

    /** @return array<string, string> */
    public function hgetall(string $key): array
    {
        $r = $this->executeRaw(['HGETALL', $key]);
        if (!\is_array($r)) {
            return [];
        }

        $out = [];
        $n = \count($r);
        for ($i = 0; $i + 1 < $n; $i += 2) {
            $k = $r[$i];
            $v = $r[$i + 1];
            if (\is_string($k) && \is_string($v)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    public function hset(string $key, string $field, string $value): int
    {
        $r = $this->executeRaw(['HSET', $key, $field, $value]);

        return $this->intFromReply($r);
    }

    public function exists(string $key): int
    {
        $r = $this->executeRaw(['EXISTS', $key]);

        return $this->intFromReply($r);
    }

    public function expire(string $key, int $seconds): int
    {
        $r = $this->executeRaw(['EXPIRE', $key, (string) $seconds]);

        return $this->intFromReply($r);
    }

    /** @param list<string> $keys */
    public function del(array $keys): int
    {
        if ([] === $keys) {
            return 0;
        }

        $argv = ['DEL', ...$keys];
        $r = $this->executeRaw($argv);

        return $this->intFromReply($r);
    }

    public function flushdb(): void
    {
        $this->executeRaw(['FLUSHDB']);
    }

    #[\Override]
    public function dbsize(): int
    {
        $r = $this->executeRaw(['DBSIZE']);

        return $this->intFromReply($r);
    }

    /**
     * @param array{MATCH?: string, COUNT?: mixed} $options
     *
     * @return array{0: int, 1: list<string>}
     */
    #[\Override]
    public function scan(int $cursor, array $options): array
    {
        $argv = ['SCAN', (string) $cursor];
        if (isset($options['MATCH'])) {
            $argv[] = 'MATCH';
            $argv[] = $options['MATCH'];
        }

        if (isset($options['COUNT'])) {
            $count = $options['COUNT'];
            if (!\is_int($count) || $count < 1) {
                throw new \InvalidArgumentException('SCAN COUNT must be a positive integer');
            }

            $argv[] = 'COUNT';
            $argv[] = (string) $count;
        }

        $r = $this->executeRaw($argv);
        if (!\is_array($r) || \count($r) < 2) {
            return [0, []];
        }

        $rawCursor = $r[0];
        $keys = $r[1];
        $next = $this->intFromReply($rawCursor);
        if (!\is_array($keys)) {
            return [$next, []];
        }

        $list = [];
        foreach ($keys as $k) {
            if (\is_string($k)) {
                $list[] = $k;
            }
        }

        return [$next, $list];
    }

    /**
     * @return array<string, mixed> nested sections (section => [key => value])
     */
    #[\Override]
    public function info(?string $section = null): array
    {
        $argv = null === $section || '' === $section ? ['INFO'] : ['INFO', $section];
        $r = $this->executeRaw($argv);
        if (!\is_string($r) || '' === $r) {
            return [];
        }

        return $this->parseInfoResponse($r);
    }

    #[\Override]
    public function object(string $subcommand, string $key): mixed
    {
        return $this->executeRaw(['OBJECT', $subcommand, $key]);
    }

    private function ensureConnected(): void
    {
        if (!\is_resource($this->stream)) {
            $this->connect();
        }
    }

    /**
     * @param list<string> $argv
     */
    private function writeArgv(array $argv): void
    {
        $buf = '*'.\count($argv)."\r\n";
        foreach ($argv as $a) {
            $buf .= '$'.\strlen($a)."\r\n".$a."\r\n";
        }

        $this->writeAllOrThrow($buf, 'Redis write failed');
    }

    /**
     * Loops until the whole {@code $buf} is written. Even on a blocking socket
     * a single {@code fwrite()} call can return a short count (e.g. when the
     * kernel send buffer is full), and that's not a "write failed" condition —
     * just resume from the offset.
     */
    private function writeAllOrThrow(string $buf, string $errorMessage): void
    {
        if (!\is_resource($this->stream)) {
            throw new \RuntimeException('Redis not connected');
        }

        $stream = $this->stream;
        $offset = 0;
        $total = \strlen($buf);

        while ($offset < $total) {
            $chunk = $offset > 0 ? substr($buf, $offset) : $buf;
            $written = @fwrite($stream, $chunk);
            if (false === $written || 0 === $written) {
                $this->checkTimeout();
                throw new \RuntimeException($errorMessage);
            }

            $offset += $written;
        }
    }

    private function readLineBytes(): string
    {
        if (!\is_resource($this->stream)) {
            throw new \RuntimeException('Redis not connected');
        }

        $line = @fgets($this->stream);
        if (false === $line) {
            $this->checkTimeout();
            throw new \RuntimeException('Redis read failed (connection closed?)');
        }

        return rtrim($line, "\r\n");
    }

    private function checkTimeout(): void
    {
        if (!\is_resource($this->stream)) {
            return;
        }

        $meta = stream_get_meta_data($this->stream);
        if ($meta['timed_out']) {
            throw new \RuntimeException('Redis read timed out');
        }
    }

    private function readReply(): mixed
    {
        if (!\is_resource($this->stream)) {
            throw new \RuntimeException('Redis not connected');
        }

        $type = @fread($this->stream, 1);
        if (false === $type || '' === $type) {
            $this->checkTimeout();
            throw new \RuntimeException('Redis unexpected EOF');
        }

        return match ($type) {
            '+' => $this->readLineBytes(),
            '-' => throw new RedisCommandException($this->readLineBytes()),
            ':' => (int) $this->readLineBytes(),
            '$' => $this->readBulkStringAfterLengthPrefix(),
            '*' => $this->readArrayAfterCountPrefix(),
            '_' => $this->readNullAggregate(),
            default => throw new \RuntimeException('Unsupported RESP type: '.$type),
        };
    }

    private function readNullAggregate(): null
    {
        $this->readLineBytes();

        return null;
    }

    private function readBulkStringAfterLengthPrefix(): ?string
    {
        $lenLine = $this->readLineBytes();
        if (!is_numeric($lenLine)) {
            throw new \RuntimeException('Invalid bulk string length');
        }

        $n = (int) $lenLine;
        if ($n < 0) {
            return null;
        }

        if (0 === $n) {
            $this->expectCrlfAfterBulk();

            return '';
        }

        $data = $this->readFixed($n);
        $this->expectCrlfAfterBulk();

        return $data;
    }

    private function readFixed(int $n): string
    {
        if (!\is_resource($this->stream)) {
            throw new \RuntimeException('Redis not connected');
        }

        $stream = $this->stream;
        $buf = '';
        while (\strlen($buf) < $n) {
            $remaining = $n - \strlen($buf);
            if ($remaining <= 0) {
                break;
            }

            $chunk = @fread($stream, $remaining);
            if (false === $chunk || '' === $chunk) {
                $this->checkTimeout();
                throw new \RuntimeException('Redis bulk read truncated');
            }

            $buf .= $chunk;
        }

        return $buf;
    }

    private function expectCrlfAfterBulk(): void
    {
        $crlf = $this->readFixed(2);
        if ("\r\n" !== $crlf) {
            throw new \RuntimeException('Invalid RESP bulk terminator');
        }
    }

    /**
     * @return list<mixed>
     */
    private function readArrayAfterCountPrefix(): array
    {
        $countLine = $this->readLineBytes();
        if (!is_numeric($countLine)) {
            throw new \RuntimeException('Invalid array length');
        }

        $count = (int) $countLine;
        if ($count < 0) {
            return [];
        }

        if ($count > self::MAX_ARRAY_REPLY_LENGTH) {
            throw new \RuntimeException('RESP array reply exceeds safety limit ('.$count.' > '.self::MAX_ARRAY_REPLY_LENGTH.')');
        }

        $out = [];
        for ($i = 0; $i < $count; ++$i) {
            $out[] = $this->readReply();
        }

        return $out;
    }

    private function drainOneReply(): void
    {
        try {
            $this->readReply();
        } catch (\Throwable) {
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseInfoResponse(string $data): array
    {
        $split = preg_split("/\r\n|\r|\n/", $data);
        $lines = false !== $split ? $split : [];
        if ([] === $lines) {
            return [];
        }

        $first = '';
        foreach ($lines as $row) {
            if ('' !== $row) {
                $first = $row;
                break;
            }
        }

        if ('' !== $first && str_starts_with($first, '#')) {
            return $this->parseInfoNewFormat($lines);
        }

        return $this->parseInfoOldFormat($lines);
    }

    /**
     * @param list<string> $lines
     *
     * @return array<string, mixed>
     */
    private function parseInfoNewFormat(array $lines): array
    {
        $info = [];
        $current = null;

        foreach ($lines as $row) {
            if ('' === $row) {
                continue;
            }

            if (1 === preg_match('/^# (\w+)$/', $row, $m)) {
                $current = $m[1];
                if (!isset($info[$current])) {
                    $info[$current] = [];
                }

                continue;
            }

            if (null === $current) {
                continue;
            }

            if (!str_contains($row, ':')) {
                continue;
            }

            [$k, $v] = explode(':', $row, 2);
            $info[$current][$k] = $v;
        }

        return $info;
    }

    /**
     * @param list<string> $lines
     *
     * @return array<string, string>
     */
    private function parseInfoOldFormat(array $lines): array
    {
        $info = [];
        foreach ($lines as $row) {
            if (!str_contains($row, ':')) {
                continue;
            }

            [$k, $v] = explode(':', $row, 2);
            $info[$k] = $v;
        }

        return $info;
    }

    private function intFromReply(mixed $r): int
    {
        if (\is_int($r)) {
            return $r;
        }

        if (\is_float($r) || \is_bool($r)) {
            return (int) $r;
        }

        if (\is_string($r) && is_numeric($r)) {
            return (int) $r;
        }

        return 0;
    }
}
