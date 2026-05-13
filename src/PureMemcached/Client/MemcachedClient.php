<?php

declare(strict_types=1);

namespace PureMemcached\Client;

use PureMemcached\Internal\ClientOptionApplier;
use PureMemcached\Internal\ConnectionStringParser;
use PureMemcached\Internal\DecodedMetaValue;
use PureMemcached\Internal\KeyFormatter;
use PureMemcached\Internal\MemcachedClientCore;
use PureMemcached\Internal\MetaReader;
use PureMemcached\Internal\MetaResult;
use PureMemcached\Internal\MetaValueReader;
use PureMemcached\Internal\StreamConnection;
use PureMemcached\Internal\TextProtocolClient;
use PureMemcached\Internal\ValueCodec;

final class MemcachedClient extends MemcachedConstants
{
    private MemcachedClientCore $core;

    private bool $pristine = true;

    /** @var array<string, MemcachedClientCore> */
    private static array $persistentPool = [];

    /** @var non-empty-string|null */
    private ?string $poolKey = null;

    public function __construct(private readonly ?string $persistentId = null, ?callable $callback = null, ?string $connection_str = null)
    {
        $pid = (null !== $this->persistentId && '' !== $this->persistentId) ? $this->persistentId : null;

        if (null !== $pid && isset(self::$persistentPool[$pid])) {
            $this->core = self::$persistentPool[$pid];
            $this->poolKey = $pid;
            $this->pristine = false;

            return;
        }

        $this->core = MemcachedClientCore::createFresh($pid);

        if (null !== $connection_str && '' !== $connection_str) {
            foreach (ConnectionStringParser::parseServers($connection_str) as $s) {
                $this->core->selector->addServer(['host' => $s['host'], 'port' => $s['port'], 'weight' => $s['weight']]);
            }

            $this->core->conn->resetPool();
        }

        if (null !== $callback) {
            try {
                $callback($this, $pid);
            } catch (\Throwable $e) {
                $this->core->conn->closeAll();
                throw $e;
            }
        }

        $this->pristine = true;

        if (null !== $pid) {
            self::$persistentPool[$pid] = $this->core;
            $this->poolKey = $pid;
        }
    }

    public function __destruct()
    {
        if (null !== $this->poolKey) {
            // Persistent clients are process-lifetime, matching PECL Memcached's in-process resource reuse.
            return;
        }

        try {
            $this->core->conn->closeAll();
        } catch (\Throwable) {
            // Destructors must not throw.
        }
    }

    public function getResultCode(): int
    {
        return $this->core->resultCode;
    }

    public function getResultMessage(): string
    {
        return $this->core->resultMessage;
    }

    private function setResult(int $code, ?string $message = null): void
    {
        $this->core->resultCode = $code;
        $this->core->resultMessage = $message ?? $this->defaultMessage($code);
    }

    private function defaultMessage(int $code): string
    {
        return match ($code) {
            self::RES_SUCCESS => 'SUCCESS',
            self::RES_NOTFOUND => 'NOT FOUND',
            self::RES_DATA_EXISTS => 'DATA EXISTS',
            self::RES_NOTSTORED => 'NOT STORED',
            self::RES_FAILURE => 'FAILURE',
            self::RES_NO_SERVERS => 'NO SERVERS',
            self::RES_BAD_KEY_PROVIDED => 'BAD KEY',
            self::RES_PAYLOAD_FAILURE => 'PAYLOAD FAILURE',
            self::RES_NOT_SUPPORTED => 'NOT SUPPORTED',
            default => 'UNKNOWN',
        };
    }

    public function addServer(string $host, int $port, int $weight = 0): bool
    {
        if ('' === $host) {
            $host = 'localhost';
        }

        if (0 === $port) {
            $port = 11211;
        }

        if ($port < 0) {
            $this->setResult(self::RES_INVALID_ARGUMENTS);

            return false;
        }

        $this->core->selector->addServer(['host' => $host, 'port' => $port, 'weight' => $weight]);
        $this->core->conn->resetPool();
        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    /**
     * @param array<mixed> $servers
     */
    public function addServers(array $servers): bool
    {
        $validated = [];
        foreach ($servers as $s) {
            if (\is_array($s)) {
                if (isset($s[0], $s[1])) {
                    $w = $s[2] ?? 0;
                    $validated[] = ['host' => $this->arrayValueToString($s[0]), 'port' => $this->arrayValueToInt($s[1]), 'weight' => $this->arrayValueToInt($w)];
                    continue;
                }

                if (isset($s['host'], $s['port'])) {
                    $w = $s['weight'] ?? 0;
                    $validated[] = ['host' => $this->arrayValueToString($s['host']), 'port' => $this->arrayValueToInt($s['port']), 'weight' => $this->arrayValueToInt($w)];
                    continue;
                }
            }

            $this->setResult(self::RES_FAILURE, 'invalid server entry');

            return false;
        }

        foreach ($validated as $server) {
            $this->core->selector->addServer($server);
        }

        $this->core->conn->resetPool();
        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    /**
     * @return list<array{host:string,port:int,type:string,weight:int}>
     */
    public function getServerList(): array
    {
        $out = [];
        foreach ($this->core->selector->getServers() as $s) {
            $out[] = ['host' => $s['host'], 'port' => $s['port'], 'type' => 'TCP', 'weight' => $s['weight']];
        }

        return $out;
    }

    /**
     * @return array{host:string,port:int,weight:int}|false
     */
    public function getServerByKey(string $server_key): array|false
    {
        if (!$this->checkKeyInternal($server_key)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if ([] === $this->core->selector->getServers()) {
            $this->setResult(self::RES_NO_SERVERS);

            return false;
        }

        $idx = $this->core->selector->pickServerIndex($server_key);
        $s = $this->core->selector->getServers()[$idx];
        $this->setResult(self::RES_SUCCESS);

        return ['host' => $s['host'], 'port' => $s['port'], 'weight' => $s['weight']];
    }

    public function resetServerList(): bool
    {
        $this->core->selector->reset();
        $this->core->conn->closeAll();
        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    /**
     * @param array<mixed>      $host_map
     * @param array<mixed>|null $forward_map
     */
    public function setBucket(array $host_map, ?array $forward_map, int $replicas): bool
    {
        if ([] === $host_map) {
            trigger_error('Memcached::setBucket(): server map cannot be empty', \E_USER_WARNING);
            $this->setResult(self::RES_INVALID_ARGUMENTS);

            return false;
        }

        if (null !== $forward_map && \count($forward_map) !== \count($host_map)) {
            trigger_error('Memcached::setBucket(): forward_map length must match the server_map length', \E_USER_WARNING);
            $this->setResult(self::RES_INVALID_ARGUMENTS);

            return false;
        }

        if ($replicas < 0) {
            trigger_error('Memcached::setBucket(): replicas must be larger than zero', \E_USER_WARNING);
            $this->setResult(self::RES_INVALID_ARGUMENTS);

            return false;
        }

        if (!$this->bucketMapValuesAreValid($host_map) || (null !== $forward_map && !$this->bucketMapValuesAreValid($forward_map))) {
            trigger_error('Memcached::setBucket(): the map must contain positive integers', \E_USER_WARNING);
            $this->setResult(self::RES_INVALID_ARGUMENTS);

            return false;
        }

        $fwd = null !== $forward_map ? array_map($this->bucketMapValueToInt(...), array_values($forward_map)) : null;
        $this->core->selector->setBucket(array_map($this->bucketMapValueToInt(...), array_values($host_map)), $replicas, $fwd);
        $this->core->conn->resetPool();
        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    public function quit(): bool
    {
        try {
            $this->core->conn->closeAll();
        } catch (\Throwable $throwable) {
            $this->setResult(self::RES_WRITE_FAILURE, $throwable->getMessage());

            return false;
        }

        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    public function flushBuffers(): bool
    {
        try {
            $this->core->conn->flushAllBuffers();
        } catch (\Throwable $throwable) {
            $this->setResult(self::RES_WRITE_FAILURE, $throwable->getMessage());

            return false;
        }

        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    public function getLastErrorMessage(): string
    {
        return $this->core->resultMessage;
    }

    public function getLastErrorCode(): int
    {
        return $this->core->resultCode;
    }

    public function getLastErrorErrno(): int
    {
        return $this->core->lastErrorErrno;
    }

    /**
     * @return array{host:string,port:int,weight:int,type:string}|false
     */
    public function getLastDisconnectedServer(): array|false
    {
        return $this->core->lastDisconnectedServer ?? false;
    }

    public function getOption(int $option): mixed
    {
        $value = $this->core->options[$option] ?? null;
        if (\is_bool($value) && $this->optionReturnsIntegerBoolean($option)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    public function setOption(int $option, mixed $value): bool
    {
        $result = ClientOptionApplier::apply($this->core, $option, $value);
        $this->setResult($result->code, $result->message);

        return $result->ok;
    }

    /**
     * @param array<mixed> $options
     */
    public function setOptions(array $options): bool
    {
        $ok = true;
        foreach ($options as $k => $v) {
            if (!\is_int($k)) {
                $ok = false;
                continue;
            }

            if (!$this->setOption($k, $v)) {
                $ok = false;
            }
        }

        return $ok;
    }

    public function isPersistent(): bool
    {
        return null !== $this->persistentId && '' !== $this->persistentId;
    }

    public function isPristine(): bool
    {
        return $this->pristine;
    }

    public function checkKey(string $key): bool
    {
        return $this->checkKeyInternal($this->prefixedKey($key));
    }

    public function setEncodingKey(string $key): bool
    {
        $this->setResult(self::RES_NOT_SUPPORTED, 'encoding not supported');

        return false;
    }

    public function setSaslAuthData(string $username, #[\SensitiveParameter] string $password): bool
    {
        $this->setResult(self::RES_NOT_SUPPORTED);

        return false;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function getStats(?string $type = null): array|false
    {
        $this->flushNetworkWrites();
        if ([] === $this->core->selector->getServers()) {
            $this->setResult(self::RES_NO_SERVERS);

            return false;
        }

        $out = [];
        $ok = true;
        foreach ($this->core->selector->getServers() as $i => $s) {
            $label = $s['host'].':'.$s['port'];
            try {
                $c = $this->core->conn->get($i);
                $st = TextProtocolClient::stats($c, $type);
                if (false === $st) {
                    $ok = false;
                }

                $out[$label] = $st;
            } catch (\Exception $exception) {
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
    public function getVersion(): array|false
    {
        $this->flushNetworkWrites();
        if ([] === $this->core->selector->getServers()) {
            $this->setResult(self::RES_NO_SERVERS);

            return false;
        }

        $out = [];
        $ok = true;
        foreach ($this->core->selector->getServers() as $i => $s) {
            $label = $s['host'].':'.$s['port'];
            try {
                $c = $this->core->conn->get($i);
                $v = TextProtocolClient::version($c);
                if (false === $v) {
                    $ok = false;
                }

                $out[$label] = false === $v ? '' : $v;
            } catch (\Exception $exception) {
                $this->recordServerFailure($i, $exception);
                $ok = false;
                $out[$label] = '';
            }
        }

        $this->setResult($ok ? self::RES_SUCCESS : self::RES_SOME_ERRORS);

        return $out;
    }

    public function flush(int $delay = 0): bool
    {
        $this->flushNetworkWrites();
        if ([] === $this->core->selector->getServers()) {
            $this->setResult(self::RES_NO_SERVERS);

            return false;
        }

        $ok = true;
        foreach (array_keys($this->core->selector->getServers()) as $i) {
            try {
                $c = $this->core->conn->get($i);
                if (!TextProtocolClient::flushAll($c, $delay)) {
                    $ok = false;
                }
            } catch (\Exception $exception) {
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
    public function getAllKeys(): array|false
    {
        $this->flushNetworkWrites();
        if ([] === $this->core->selector->getServers()) {
            $this->setResult(self::RES_NO_SERVERS);

            return false;
        }

        $keys = [];
        $ok = true;
        $hadSuccess = false;
        foreach (array_keys($this->core->selector->getServers()) as $i) {
            try {
                $c = $this->core->conn->get($i);
                $k = TextProtocolClient::getAllKeys($c);
                if (\is_array($k)) {
                    $hadSuccess = true;
                    $keys = array_merge($keys, $k);
                } else {
                    $ok = false;
                }
            } catch (\Exception $exception) {
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

    public function get(string $key, ?callable $cache_cb = null, int $get_flags = 0): mixed
    {
        $this->pristine = false;
        $this->flushNetworkWrites();
        $pk = $this->prefixedKey($key);
        if (!$this->checkKeyInternal($pk)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if (!$this->ensureServersAvailable()) {
            return false;
        }

        $idx = $this->core->selector->pickServerIndex($this->routingKey($key));
        try {
            $c = $this->core->conn->get($idx);
            $flags = 'v f t c';
            if (($get_flags & self::GET_EXTENDED) !== 0) {
                $flags = 'v f t c';
            }

            [$encodedPk, $bFlag] = KeyFormatter::encodeMetaKey($pk);
            $line = 'mg '.$encodedPk.' '.$flags.$bFlag."\r\n";
            $this->send($c, $line);
            $reader = new MetaReader($c);
            $item = $this->readDecodedMetaValue($reader);
            if (false === $item) {
                return false;
            }

            if (!$item->found) {
                if (null !== $cache_cb) {
                    return $this->invokeCacheCb($cache_cb, $key, $get_flags);
                }

                $this->setResult(self::RES_NOTFOUND);

                return false;
            }

            $this->setResult(self::RES_SUCCESS);

            return $this->valueForGetFlags($item->value, $item->result(), $get_flags);
        } catch (\Exception $exception) {
            $this->recordServerFailure($idx, $exception);
            $this->setResult(self::RES_FAILURE, $exception->getMessage());

            return false;
        }
    }

    private function invokeCacheCbByKey(callable $cache_cb, string $server_key, string $key, int $get_flags): mixed
    {
        $value = null;
        $exp = 0;
        $cas = 0.0;
        $ok = $this->callCacheCallback($cache_cb, $key, $value, $exp, $cas);
        if ($ok && null !== $value) {
            $this->setByKey($server_key, $key, $value, $exp);

            return $this->getByKey($server_key, $key, null, $get_flags);
        }

        $this->setResult(self::RES_NOTFOUND);

        return false;
    }

    private function invokeCacheCb(callable $cache_cb, string $key, int $get_flags): mixed
    {
        $value = null;
        $exp = 0;
        $cas = 0.0;
        $ok = $this->callCacheCallback($cache_cb, $key, $value, $exp, $cas);
        if ($ok && null !== $value) {
            $this->set($key, $value, $exp);

            return $this->get($key, null, $get_flags);
        }

        $this->setResult(self::RES_NOTFOUND);

        return false;
    }

    private function callCacheCallback(callable $cacheCb, string $key, mixed &$value, int &$expiration, float &$cas): bool
    {
        return true === $cacheCb($this, $key, $value, $expiration, $cas);
    }

    public function getByKey(string $server_key, string $key, ?callable $cache_cb = null, int $get_flags = 0): mixed
    {
        $this->pristine = false;
        $this->flushNetworkWrites();
        $pk = $this->prefixedKey($key);
        if (!$this->checkKeyInternal($pk) || !$this->checkKeyInternal($server_key)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if (!$this->ensureServersAvailable()) {
            return false;
        }

        $idx = $this->core->selector->pickServerIndex($server_key);
        try {
            $c = $this->core->conn->get($idx);
            $flags = 'v f t c';
            [$encodedPk, $bFlag] = KeyFormatter::encodeMetaKey($pk);
            $this->send($c, 'mg '.$encodedPk.' '.$flags.$bFlag."\r\n");
            $reader = new MetaReader($c);
            $item = $this->readDecodedMetaValue($reader);
            if (false === $item) {
                return false;
            }

            if (!$item->found) {
                if (null !== $cache_cb) {
                    return $this->invokeCacheCbByKey($cache_cb, $server_key, $key, $get_flags);
                }

                $this->setResult(self::RES_NOTFOUND);

                return false;
            }

            $this->setResult(self::RES_SUCCESS);

            return $this->valueForGetFlags($item->value, $item->result(), $get_flags);
        } catch (\Exception $exception) {
            $this->recordServerFailure($idx, $exception);
            $this->setResult(self::RES_FAILURE, $exception->getMessage());

            return false;
        }
    }

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, mixed>|false
     */
    public function getMulti(array $keys, int $get_flags = 0): array|false
    {
        $this->pristine = false;
        $this->flushNetworkWrites();
        if ([] === $keys) {
            $this->setResult(self::RES_SUCCESS);

            return [];
        }

        $preserve = ($get_flags & self::GET_PRESERVE_ORDER) !== 0;
        $byServer = [];
        foreach ($keys as $k) {
            $ks = $this->keyToString($k);
            $pk = $this->prefixedKey($ks);
            if (!$this->checkKeyInternal($pk)) {
                $this->setResult(self::RES_BAD_KEY_PROVIDED);

                return false;
            }

            $idx = $this->core->selector->pickServerIndex($this->routingKey($ks));
            $byServer[$idx][] = [$ks, $pk];
        }

        if (!$this->ensureServersAvailable()) {
            return false;
        }

        try {
            $found = [];
            foreach ($byServer as $idx => $pairs) {
                $c = $this->core->conn->get($idx);
                foreach ($pairs as [, $pk]) {
                    [$encodedPk, $bFlag] = KeyFormatter::encodeMetaKey($pk);
                    $this->send($c, 'mg '.$encodedPk.' v f t c'.$bFlag."\r\n");
                }

                $reader = new MetaReader($c);
                foreach ($pairs as [$origKey]) {
                    $item = $this->readDecodedMetaValue($reader);
                    if (false === $item) {
                        return false;
                    }

                    if ($item->found) {
                        $found[$origKey] = $this->valueForGetFlags($item->value, $item->result(), $get_flags);
                    }
                }
            }
        } catch (\Exception $exception) {
            $this->recordServerFailure($idx, $exception);
            $this->setResult(self::RES_FAILURE, $exception->getMessage());

            return false;
        }

        $this->setResult(self::RES_SUCCESS);
        if ($preserve) {
            $ordered = [];
            foreach ($keys as $k) {
                $ks = $this->keyToString($k);
                $ordered[$ks] = $found[$ks] ?? null;
            }

            return $ordered;
        }

        return $found;
    }

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, mixed>|false
     */
    public function getMultiByKey(string $server_key, array $keys, int $get_flags = 0): array|false
    {
        $this->pristine = false;
        $this->flushNetworkWrites();
        if (!$this->checkKeyInternal($server_key)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if ([] === $keys) {
            $this->setResult(self::RES_SUCCESS);

            return [];
        }

        foreach ($keys as $k) {
            $pk = $this->prefixedKey($this->keyToString($k));
            if (!$this->checkKeyInternal($pk)) {
                $this->setResult(self::RES_BAD_KEY_PROVIDED);

                return false;
            }
        }

        if (!$this->ensureServersAvailable()) {
            return false;
        }

        $preserve = ($get_flags & self::GET_PRESERVE_ORDER) !== 0;
        try {
            $idx = $this->core->selector->pickServerIndex($server_key);
            $c = $this->core->conn->get($idx);
            $found = [];
            foreach ($keys as $k) {
                $pk = $this->prefixedKey($this->keyToString($k));
                [$encodedPk, $bFlag] = KeyFormatter::encodeMetaKey($pk);
                $this->send($c, 'mg '.$encodedPk.' v f t c'.$bFlag."\r\n");
            }

            $reader = new MetaReader($c);
            foreach ($keys as $k) {
                $orig = $this->keyToString($k);
                $item = $this->readDecodedMetaValue($reader);
                if (false === $item) {
                    return false;
                }

                if ($item->found) {
                    $found[$orig] = $this->valueForGetFlags($item->value, $item->result(), $get_flags);
                }
            }
        } catch (\Exception $exception) {
            $this->recordServerFailure($idx ?? null, $exception);
            $this->setResult(self::RES_FAILURE, $exception->getMessage());

            return false;
        }

        $this->setResult(self::RES_SUCCESS);
        if ($preserve) {
            $ordered = [];
            foreach ($keys as $k) {
                $ks = $this->keyToString($k);
                $ordered[$ks] = $found[$ks] ?? null;
            }

            return $ordered;
        }

        return $found;
    }

    /**
     * @param array<mixed> $keys
     */
    public function getDelayed(array $keys, bool $with_cas = false, ?callable $value_cb = null): bool
    {
        if ([] === $keys) {
            $this->setResult(self::RES_SUCCESS);

            return true;
        }

        foreach ($keys as $k) {
            if (!$this->checkKeyInternal($this->prefixedKey($this->keyToString($k)))) {
                $this->setResult(self::RES_BAD_KEY_PROVIDED);

                return false;
            }
        }

        if (!$this->ensureServersAvailable()) {
            return false;
        }

        if (null !== $value_cb) {
            $this->pristine = false;

            return $this->runGetDelayedWithValueCallback(null, $keys, $with_cas, $value_cb);
        }

        $this->core->delayedQueue[] = ['keys' => $this->keyStrings($keys), 'serverKey' => null, 'withCas' => $with_cas];
        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    /**
     * @param array<mixed> $keys
     */
    public function getDelayedByKey(string $server_key, array $keys, bool $with_cas = false, ?callable $value_cb = null): bool
    {
        if ([] === $keys) {
            $this->setResult(self::RES_SUCCESS);

            return true;
        }

        if (!$this->checkKeyInternal($server_key)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        foreach ($keys as $k) {
            if (!$this->checkKeyInternal($this->prefixedKey($this->keyToString($k)))) {
                $this->setResult(self::RES_BAD_KEY_PROVIDED);

                return false;
            }
        }

        if (!$this->ensureServersAvailable()) {
            return false;
        }

        if (null !== $value_cb) {
            $this->pristine = false;

            return $this->runGetDelayedWithValueCallback($server_key, $keys, $with_cas, $value_cb);
        }

        $this->core->delayedQueue[] = ['keys' => $this->keyStrings($keys), 'serverKey' => $server_key, 'withCas' => $with_cas];
        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function fetch(): array|false
    {
        if ([] === $this->core->delayedQueue && null === $this->core->delayedResults) {
            $this->setResult(self::RES_FETCH_NOTFINISHED);

            return false;
        }

        if (null === $this->core->delayedResults && !$this->primeDelayedResults()) {
            return false;
        }

        $currentResults = $this->core->delayedResults ?? [];
        if ($this->core->delayedCursor >= \count($currentResults) && [] !== $this->core->delayedQueue) {
            $this->core->delayedResults = null;
            $this->core->delayedCursor = 0;
            if (!$this->primeDelayedResults()) {
                return false;
            }

            $currentResults = $this->core->delayedResults ?? [];
        }

        if ($this->core->delayedCursor >= \count($currentResults)) {
            $this->setResult(self::RES_END);

            return false;
        }

        $r = $currentResults[$this->core->delayedCursor++];
        $this->setResult(self::RES_SUCCESS);

        return $r;
    }

    /**
     * @return list<array<string, mixed>>|false
     */
    public function fetchAll(): array|false
    {
        if ([] === $this->core->delayedQueue && null === $this->core->delayedResults) {
            $this->setResult(self::RES_FETCH_NOTFINISHED);

            return false;
        }

        if (null === $this->core->delayedResults && !$this->primeDelayedResults()) {
            return false;
        }

        $all = \array_slice($this->core->delayedResults ?? [], $this->core->delayedCursor);
        while ([] !== $this->core->delayedQueue) {
            $this->core->delayedResults = null;
            $this->core->delayedCursor = 0;
            if (!$this->primeDelayedResults()) {
                return false;
            }

            $all = array_merge($all, $this->core->delayedResults ?? []);
        }

        $this->core->delayedResults = [];
        $this->core->delayedQueue = [];
        $this->core->delayedCursor = 0;
        $this->setResult(self::RES_SUCCESS);

        return $all;
    }

    /**
     * @param array<mixed>                              $keys
     * @param callable(self, array<string, mixed>):void $value_cb
     */
    private function runGetDelayedWithValueCallback(?string $server_key, array $keys, bool $with_cas, callable $value_cb): bool
    {
        $this->flushNetworkWrites();
        if ([] === $keys) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if (null !== $server_key && !$this->checkKeyInternal($server_key)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        foreach ($keys as $k) {
            $pk = $this->prefixedKey($this->keyToString($k));
            if (!$this->checkKeyInternal($pk)) {
                $this->setResult(self::RES_BAD_KEY_PROVIDED);

                return false;
            }
        }

        if (!$this->ensureServersAvailable()) {
            return false;
        }

        try {
            if (null === $server_key) {
                $by = [];
                foreach ($keys as $k) {
                    $ks = $this->keyToString($k);
                    $pk = $this->prefixedKey($ks);
                    $idx = $this->core->selector->pickServerIndex($this->routingKey($ks));
                    $by[$idx][] = [$ks, $pk];
                }

                foreach ($by as $idx => $pairs) {
                    $c = $this->core->conn->get($idx);
                    foreach ($pairs as [, $pk]) {
                        [$encodedPk, $bFlag] = KeyFormatter::encodeMetaKey($pk);
                        $this->send($c, 'mg '.$encodedPk.' v f t c'.$bFlag."\r\n");
                    }

                    $reader = new MetaReader($c);
                    foreach ($pairs as [$orig]) {
                        $this->dispatchDelayedValueCb($reader, $orig, $with_cas, $value_cb);
                    }
                }
            } else {
                $idx = $this->core->selector->pickServerIndex($server_key);
                $c = $this->core->conn->get($idx);
                foreach ($keys as $k) {
                    $pk = $this->prefixedKey($this->keyToString($k));
                    [$encodedPk, $bFlag] = KeyFormatter::encodeMetaKey($pk);
                    $this->send($c, 'mg '.$encodedPk.' v f t c'.$bFlag."\r\n");
                }

                $reader = new MetaReader($c);
                foreach ($keys as $k) {
                    $this->dispatchDelayedValueCb($reader, $this->keyToString($k), $with_cas, $value_cb);
                }
            }
        } catch (\Exception $exception) {
            $this->recordServerFailure($idx ?? null, $exception);
            $this->setResult(self::RES_FAILURE, $exception->getMessage());

            return false;
        }

        $this->setResult(self::RES_SUCCESS);

        return true;
    }

    /**
     * @param callable(self, array<string, mixed>):void $value_cb
     */
    private function dispatchDelayedValueCb(MetaReader $reader, string $origKey, bool $with_cas, callable $value_cb): void
    {
        $item = $this->readDecodedMetaValue($reader);
        if (false === $item || !$item->found) {
            return;
        }

        $callbackItem = ['key' => $origKey, 'value' => $item->value];
        if ($with_cas) {
            $cas = $this->casValue($item->result()->getCas());
            $callbackItem['cas'] = $cas;
            $callbackItem['flags'] = ValueCodec::getUserFlags((int) ($item->result()->getToken('f') ?? '0'));
        }

        $value_cb($this, $callbackItem);
    }

    private function primeDelayedResults(): bool
    {
        $batch = array_shift($this->core->delayedQueue);
        if (null === $batch) {
            $this->core->delayedResults = [];

            return true;
        }

        $this->flushNetworkWrites();
        if (!$this->ensureServersAvailable()) {
            $this->core->delayedResults = [];
            $this->core->delayedCursor = 0;

            return false;
        }

        $results = [];
        if (null === $batch['serverKey']) {
            $by = [];
            foreach ($batch['keys'] as $k) {
                $pk = $this->prefixedKey($k);
                $idx = $this->core->selector->pickServerIndex($this->routingKey($k));
                $by[$idx][] = [$k, $pk];
            }

            foreach ($by as $idx => $pairs) {
                $c = $this->core->conn->get($idx);
                foreach ($pairs as [, $pk]) {
                    [$encodedPk, $bFlag] = KeyFormatter::encodeMetaKey($pk);
                    $this->send($c, 'mg '.$encodedPk.' v f t c'.$bFlag."\r\n");
                }

                $reader = new MetaReader($c);
                foreach ($pairs as [$orig, $pk]) {
                    $item = $this->readDecodedMetaValue($reader);
                    if (false === $item) {
                        $this->core->delayedResults = [];
                        $this->core->delayedCursor = 0;

                        return false;
                    }

                    if ($item->found) {
                        $entry = $this->delayedEntry($orig, $item->value, $item->result(), $batch['withCas']);
                        $results[] = $entry;
                    }
                }
            }
        } else {
            $idx = $this->core->selector->pickServerIndex($batch['serverKey']);
            $c = $this->core->conn->get($idx);
            foreach ($batch['keys'] as $k) {
                $pk = $this->prefixedKey($k);
                [$encodedPk, $bFlag] = KeyFormatter::encodeMetaKey($pk);
                $this->send($c, 'mg '.$encodedPk.' v f t c'.$bFlag."\r\n");
            }

            $reader = new MetaReader($c);
            foreach ($batch['keys'] as $k) {
                $item = $this->readDecodedMetaValue($reader);
                if (false === $item) {
                    $this->core->delayedResults = [];
                    $this->core->delayedCursor = 0;

                    return false;
                }

                if ($item->found) {
                    $results[] = $this->delayedEntry($k, $item->value, $item->result(), $batch['withCas']);
                }
            }
        }

        $this->core->delayedResults = $results;
        $this->core->delayedCursor = 0;

        return true;
    }

    public function set(string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->storeInternal($key, $value, $expiration, 'S', null, null);
    }

    public function setByKey(string $server_key, string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->storeInternal($key, $value, $expiration, 'S', $server_key, null);
    }

    public function touch(string $key, int $expiration = 0): bool
    {
        return $this->touchInternal($key, $expiration, null);
    }

    public function touchByKey(string $server_key, string $key, int $expiration = 0): bool
    {
        return $this->touchInternal($key, $expiration, $server_key);
    }

    private function touchInternal(string $key, int $expiration, ?string $server_key): bool
    {
        $this->pristine = false;
        $this->flushNetworkWrites();
        $pk = $this->prefixedKey($key);
        if (!$this->checkKeyInternal($pk)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if (null !== $server_key && !$this->checkKeyInternal($server_key)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if (!$this->ensureServersAvailable()) {
            return false;
        }

        try {
            $idx = $this->core->selector->pickServerIndex($server_key ?? $this->routingKey($key));
            $c = $this->core->conn->get($idx);
            $t = $this->ttlToken($expiration);
            [$encodedPk, $bFlag] = KeyFormatter::encodeMetaKey($pk);
            $this->send($c, 'mg '.$encodedPk.' T'.$t.$bFlag."\r\n");
            $reader = new MetaReader($c);
            $r = $reader->readOne(false);
        } catch (\Exception $exception) {
            $this->recordServerFailure($idx ?? null, $exception);
            $this->setResult(self::RES_FAILURE, $exception->getMessage());

            return false;
        }

        if ('HD' === $r->code) {
            $this->setResult(self::RES_SUCCESS);

            return true;
        }

        if ('EN' === $r->code) {
            $this->setResult(self::RES_NOTFOUND);

            return false;
        }

        $this->setResult(self::RES_FAILURE);

        return false;
    }

    /**
     * @param array<mixed> $items
     */
    public function setMulti(array $items, int $expiration = 0): bool
    {
        foreach (array_keys($items) as $k) {
            if (!$this->checkKeyInternal($this->prefixedKey($this->keyToString($k)))) {
                $this->setResult(self::RES_BAD_KEY_PROVIDED);

                return false;
            }
        }

        if ([] !== $items && !$this->ensureServersAvailable()) {
            return false;
        }

        return $this->storeMultiInternal($items, $expiration, null);
    }

    /**
     * @param array<mixed> $items
     */
    public function setMultiByKey(string $server_key, array $items, int $expiration = 0): bool
    {
        if (!$this->checkKeyInternal($server_key)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        foreach (array_keys($items) as $k) {
            if (!$this->checkKeyInternal($this->prefixedKey($this->keyToString($k)))) {
                $this->setResult(self::RES_BAD_KEY_PROVIDED);

                return false;
            }
        }

        if ([] !== $items && !$this->ensureServersAvailable()) {
            return false;
        }

        return $this->storeMultiInternal($items, $expiration, $server_key);
    }

    public function add(string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->storeInternal($key, $value, $expiration, 'E', null, null);
    }

    public function addByKey(string $server_key, string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->storeInternal($key, $value, $expiration, 'E', $server_key, null);
    }

    public function replace(string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->storeInternal($key, $value, $expiration, 'R', null, null);
    }

    public function replaceByKey(string $server_key, string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->storeInternal($key, $value, $expiration, 'R', $server_key, null);
    }

    public function append(string $key, string $value): bool
    {
        return $this->storeInternal($key, $value, 0, 'A', null, null);
    }

    public function appendByKey(string $server_key, string $key, string $value): bool
    {
        return $this->storeInternal($key, $value, 0, 'A', $server_key, null);
    }

    public function prepend(string $key, string $value): bool
    {
        return $this->storeInternal($key, $value, 0, 'P', null, null);
    }

    public function prependByKey(string $server_key, string $key, string $value): bool
    {
        return $this->storeInternal($key, $value, 0, 'P', $server_key, null);
    }

    public function cas(string|int|float $cas_token, string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->storeInternal($key, $value, $expiration, 'S', null, (string) $cas_token);
    }

    public function casByKey(string|int|float $cas_token, string $server_key, string $key, mixed $value, int $expiration = 0): bool
    {
        return $this->storeInternal($key, $value, $expiration, 'S', $server_key, (string) $cas_token);
    }

    private function storeInternal(string $key, mixed $value, int $expiration, string $mode, ?string $server_key, ?string $casToken): bool
    {
        $this->pristine = false;
        if (('A' === $mode || 'P' === $mode) && (bool) $this->core->options[self::OPT_COMPRESSION]) {
            trigger_error('cannot append/prepend with compression turned on', \E_USER_WARNING);
            $this->setResult(self::RES_NOTSTORED);

            return false;
        }

        try {
            [$payload, $flags] = ValueCodec::encode(
                $value,
                $this->optionInt(self::OPT_SERIALIZER, self::SERIALIZER_PHP),
                $this->optionBool(self::OPT_COMPRESSION, true),
                $this->optionInt(self::OPT_COMPRESSION_TYPE, self::COMPRESSION_TYPE_FASTLZ),
                $this->optionInt(self::OPT_COMPRESSION_LEVEL, 3),
                $this->core->compressionThreshold,
                $this->core->compressionFactor,
                $this->optionInt(self::OPT_USER_FLAGS, -1),
            );
        } catch (\Exception) {
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

        if (null !== $server_key && !$this->checkKeyInternal($server_key)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if ([] === $this->core->selector->getServers()) {
            $this->setResult(self::RES_NO_SERVERS);

            return false;
        }

        try {
            if (!$this->shouldBufferNoReplyWrite()) {
                $this->flushNetworkWrites();
            }

            $idx = $this->core->selector->pickServerIndex($server_key ?? $this->routingKey($key));
            $c = $this->core->conn->get($idx);
            $t = $this->ttlToken($expiration);
            $flagParts = ['T'.$t, 'F'.$flags, 'M'.$mode];
            if ($this->useNoReply()) {
                $flagParts[] = 'q';
            }

            if (null !== $casToken) {
                $flagParts[] = 'C'.$casToken;
            }

            [$encodedPk, $bFlag] = KeyFormatter::encodeMetaKey($pk);
            if ('' !== $bFlag) {
                $flagParts[] = trim($bFlag);
            }

            $flagStr = implode(' ', $flagParts);
            $len = \strlen($payload);
            $cmd = 'ms '.$encodedPk.' '.$len.' '.$flagStr."\r\n".$payload."\r\n";
            $this->send($c, $cmd);
            if ($this->useNoReply()) {
                $this->setResult(self::RES_SUCCESS);

                return true;
            }

            $reader = new MetaReader($c);
            $r = $reader->readOne(false);
        } catch (\Exception $exception) {
            $this->recordServerFailure($idx ?? null, $exception);
            $this->setResult(self::RES_FAILURE, $exception->getMessage());

            return false;
        }

        if ('HD' === $r->code) {
            $this->setResult(self::RES_SUCCESS);

            return true;
        }

        if ('NS' === $r->code) {
            $this->setResult(self::RES_NOTSTORED);

            return false;
        }

        if ('EX' === $r->code) {
            $this->setResult(self::RES_DATA_EXISTS);

            return false;
        }

        if ('NF' === $r->code) {
            $this->setResult(self::RES_NOTFOUND);

            return false;
        }

        $this->setResult(self::RES_FAILURE);

        return false;
    }

    /**
     * @param array<mixed> $items
     */
    private function storeMultiInternal(array $items, int $expiration, ?string $serverKey): bool
    {
        $this->pristine = false;
        if ([] === $items) {
            $this->setResult(self::RES_SUCCESS);

            return true;
        }

        $batches = [];
        $ok = true;
        foreach ($items as $key => $value) {
            $keyString = (string) $key;
            $prepared = $this->prepareStoreCommand($keyString, $value, $expiration, 'S', $serverKey, null);
            if (false === $prepared) {
                $ok = false;
                continue;
            }

            $idx = $this->core->selector->pickServerIndex($serverKey ?? $this->routingKey($keyString));
            $batches[$idx][] = $prepared;
        }

        try {
            if (!$this->shouldBufferNoReplyWrite()) {
                $this->flushNetworkWrites();
            }

            foreach ($batches as $idx => $commands) {
                $c = $this->core->conn->get($idx);
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
        } catch (\Exception $exception) {
            $this->recordServerFailure($idx ?? null, $exception);
            $this->setResult(self::RES_FAILURE, $exception->getMessage());

            return false;
        }

        $this->setResult($ok ? self::RES_SUCCESS : self::RES_SOME_ERRORS);

        return $ok;
    }

    private function prepareStoreCommand(string $key, mixed $value, int $expiration, string $mode, ?string $serverKey, ?string $casToken): string|false
    {
        try {
            [$payload, $flags] = ValueCodec::encode(
                $value,
                $this->optionInt(self::OPT_SERIALIZER, self::SERIALIZER_PHP),
                $this->optionBool(self::OPT_COMPRESSION, true),
                $this->optionInt(self::OPT_COMPRESSION_TYPE, self::COMPRESSION_TYPE_FASTLZ),
                $this->optionInt(self::OPT_COMPRESSION_LEVEL, 3),
                $this->core->compressionThreshold,
                $this->core->compressionFactor,
                $this->optionInt(self::OPT_USER_FLAGS, -1),
            );
        } catch (\Exception) {
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

        $flagParts = ['T'.$this->ttlToken($expiration), 'F'.$flags, 'M'.$mode];
        if ($this->useNoReply()) {
            $flagParts[] = 'q';
        }

        if (null !== $casToken) {
            $flagParts[] = 'C'.$casToken;
        }

        [$encodedPk, $bFlag] = KeyFormatter::encodeMetaKey($pk);
        if ('' !== $bFlag) {
            $flagParts[] = trim($bFlag);
        }

        $payloadLength = \strlen($payload);

        return 'ms '.$encodedPk.' '.$payloadLength.' '.implode(' ', $flagParts)."\r\n".$payload."\r\n";
    }

    private function storeSucceeded(MetaResult $result): bool
    {
        if ('HD' === $result->code) {
            return true;
        }

        if ('NS' === $result->code) {
            $this->setResult(self::RES_NOTSTORED);

            return false;
        }

        if ('EX' === $result->code) {
            $this->setResult(self::RES_DATA_EXISTS);

            return false;
        }

        if ('NF' === $result->code) {
            $this->setResult(self::RES_NOTFOUND);

            return false;
        }

        $this->setResult(self::RES_FAILURE);

        return false;
    }

    public function delete(string $key, int $time = 0): bool
    {
        return $this->deleteInternal($key, null, $time);
    }

    public function deleteByKey(string $server_key, string $key, int $time = 0): bool
    {
        return $this->deleteInternal($key, $server_key, $time);
    }

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, bool|int>
     */
    public function deleteMulti(array $keys, int $time = 0): array
    {
        $out = [];
        $keyStrings = $this->keyStrings($keys);
        foreach ($keys as $k) {
            if (!$this->checkKeyInternal($this->prefixedKey($this->keyToString($k)))) {
                $this->setResult(self::RES_BAD_KEY_PROVIDED);

                return array_fill_keys($keyStrings, self::RES_BAD_KEY_PROVIDED);
            }
        }

        if (!$this->acceptDeleteTime($time)) {
            return array_fill_keys($keyStrings, $this->core->resultCode);
        }

        foreach ($keys as $k) {
            $ks = $this->keyToString($k);
            $ok = $this->deleteInternal($ks, null, 0);
            $out[$ks] = $ok ? true : $this->core->resultCode;
        }

        return $out;
    }

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, bool|int>
     */
    public function deleteMultiByKey(string $server_key, array $keys, int $time = 0): array
    {
        $out = [];
        $keyStrings = $this->keyStrings($keys);
        if (!$this->checkKeyInternal($server_key)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return array_fill_keys($keyStrings, self::RES_BAD_KEY_PROVIDED);
        }

        foreach ($keys as $k) {
            if (!$this->checkKeyInternal($this->prefixedKey($this->keyToString($k)))) {
                $this->setResult(self::RES_BAD_KEY_PROVIDED);

                return array_fill_keys($keyStrings, self::RES_BAD_KEY_PROVIDED);
            }
        }

        if (!$this->acceptDeleteTime($time)) {
            return array_fill_keys($keyStrings, $this->core->resultCode);
        }

        foreach ($keys as $k) {
            $ks = $this->keyToString($k);
            $ok = $this->deleteInternal($ks, $server_key, 0);
            $out[$ks] = $ok ? true : $this->core->resultCode;
        }

        return $out;
    }

    private function deleteInternal(string $key, ?string $server_key, int $time): bool
    {
        $this->pristine = false;
        if (!$this->shouldBufferNoReplyWrite()) {
            $this->flushNetworkWrites();
        }

        $pk = $this->prefixedKey($key);
        if (!$this->checkKeyInternal($pk)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if (null !== $server_key && !$this->checkKeyInternal($server_key)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if (!$this->acceptDeleteTime($time)) {
            return false;
        }

        if (!$this->ensureServersAvailable()) {
            return false;
        }

        try {
            $idx = $this->core->selector->pickServerIndex($server_key ?? $this->routingKey($key));
            $c = $this->core->conn->get($idx);
            [$encodedPk, $bFlag] = KeyFormatter::encodeMetaKey($pk);
            $flags = '' === $bFlag ? [] : [trim($bFlag)];
            if ($this->useNoReply()) {
                $flags[] = 'q';
            }

            $suffix = [] === $flags ? '' : ' '.implode(' ', $flags);
            $this->send($c, 'md '.$encodedPk.$suffix."\r\n");
            if ($this->useNoReply()) {
                $this->setResult(self::RES_SUCCESS);

                return true;
            }

            $reader = new MetaReader($c);
            $r = $reader->readOne(false);
        } catch (\Exception $exception) {
            $this->setResult(self::RES_FAILURE, $exception->getMessage());

            return false;
        }

        if ('HD' === $r->code) {
            $this->setResult(self::RES_SUCCESS);

            return true;
        }

        if ('NF' === $r->code) {
            $this->setResult(self::RES_NOTFOUND);

            return false;
        }

        $this->setResult(self::RES_FAILURE);

        return false;
    }

    private function acceptDeleteTime(int $time): bool
    {
        if ($time < 0) {
            $this->setResult(self::RES_INVALID_ARGUMENTS, 'delete time must be non-negative');

            return false;
        }

        if ($time > 0) {
            $this->setResult(self::RES_NOT_SUPPORTED, 'delayed delete is not supported by the meta protocol');

            return false;
        }

        return true;
    }

    public function increment(string $key, int $offset = 1, int $initial_value = 0, int $expiry = 0): int|false
    {
        return $this->arith($key, $offset, false, null);
    }

    public function decrement(string $key, int $offset = 1, int $initial_value = 0, int $expiry = 0): int|false
    {
        return $this->arith($key, $offset, true, null);
    }

    public function incrementByKey(string $server_key, string $key, int $offset = 1, int $initial_value = 0, int $expiry = 0): int|false
    {
        return $this->arith($key, $offset, false, $server_key);
    }

    public function decrementByKey(string $server_key, string $key, int $offset = 1, int $initial_value = 0, int $expiry = 0): int|false
    {
        return $this->arith($key, $offset, true, $server_key);
    }

    private function arith(string $key, int $offset, bool $decr, ?string $server_key): int|false
    {
        if ($offset < 0) {
            trigger_error('offset cannot be a negative value', \E_USER_WARNING);
            $this->setResult(self::RES_INVALID_ARGUMENTS);

            return false;
        }

        $this->pristine = false;
        $this->flushNetworkWrites();
        $pk = $this->prefixedKey($key);
        if (!$this->checkKeyInternal($pk)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if (null !== $server_key && !$this->checkKeyInternal($server_key)) {
            $this->setResult(self::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if (!$this->ensureServersAvailable()) {
            return false;
        }

        try {
            $idx = $this->core->selector->pickServerIndex($server_key ?? $this->routingKey($key));
            $c = $this->core->conn->get($idx);
            [$encodedPk, $bFlag] = KeyFormatter::encodeMetaKey($pk);
            $parts = ['D'.$offset, $decr ? 'MD' : 'MI', 'v'];

            if ('' !== $bFlag) {
                $parts[] = trim($bFlag);
            }

            $flagLine = implode(' ', $parts);
            $this->send($c, 'ma '.$encodedPk.' '.$flagLine."\r\n");
            $reader = new MetaReader($c);
            $r = $reader->readArithmeticValue();
        } catch (\Exception $exception) {
            $this->setResult(self::RES_FAILURE, $exception->getMessage());

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
    }

    private function prefixedKey(string $key): string
    {
        return KeyFormatter::prefixed($key, $this->core->options);
    }

    /**
     * @return array<string, mixed>
     */
    private function extendedValue(mixed $value, MetaResult $result): array
    {
        $out = ['value' => $value];
        $cas = $this->casValue($result->getCas());
        $flags = (int) ($result->getToken('f') ?? '0');
        $out['cas'] = $cas;
        $out['flags'] = ValueCodec::getUserFlags($flags);

        return $out;
    }

    private function readDecodedMetaValue(MetaReader $reader): DecodedMetaValue|false
    {
        $decoded = MetaValueReader::read($reader, $this->optionInt(self::OPT_SERIALIZER, self::SERIALIZER_PHP));
        if ($decoded->isFailure()) {
            $this->setResult($decoded->errorCode ?? self::RES_FAILURE, $decoded->errorMessage);

            return false;
        }

        return $decoded;
    }

    private function valueForGetFlags(mixed $value, MetaResult $result, int $getFlags): mixed
    {
        if (($getFlags & self::GET_EXTENDED) !== 0) {
            return $this->extendedValue($value, $result);
        }

        return $value;
    }

    private function casValue(?string $cas): int|string
    {
        if (null === $cas || '' === $cas) {
            return 0;
        }

        if (!ctype_digit($cas)) {
            return $cas;
        }

        $max = (string) \PHP_INT_MAX;
        if (\strlen($cas) < \strlen($max) || (\strlen($cas) === \strlen($max) && strcmp($cas, $max) <= 0)) {
            return (int) $cas;
        }

        return $cas;
    }

    /**
     * @return array<string, mixed>
     */
    private function delayedEntry(string $key, mixed $value, MetaResult $result, bool $withCas): array
    {
        $entry = ['key' => $key, 'value' => $value];
        if ($withCas) {
            $cas = $this->casValue($result->getCas());
            $flags = (int) ($result->getToken('f') ?? '0');
            $entry['cas'] = $cas;
            $entry['flags'] = ValueCodec::getUserFlags($flags);
        }

        return $entry;
    }

    private function routingKey(string $itemKey): string
    {
        return KeyFormatter::routing($itemKey, $this->core->options);
    }

    private function checkKeyInternal(string $key): bool
    {
        return KeyFormatter::isValid($key);
    }

    private function keyToString(mixed $key): string
    {
        if (\is_scalar($key) || null === $key) {
            return (string) $key;
        }

        return '';
    }

    private function arrayValueToString(mixed $value): string
    {
        if (\is_scalar($value) || null === $value) {
            return (string) $value;
        }

        return '';
    }

    private function arrayValueToInt(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value) || \is_bool($value)) {
            return (int) $value;
        }

        if (\is_string($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function optionInt(int $option, int $default): int
    {
        $value = $this->core->options[$option] ?? $default;
        if (\is_int($value)) {
            return $value;
        }

        return $default;
    }

    private function optionBool(int $option, bool $default): bool
    {
        $value = $this->core->options[$option] ?? $default;
        if (\is_bool($value)) {
            return $value;
        }

        return $default;
    }

    private function optionReturnsIntegerBoolean(int $option): bool
    {
        return \in_array($option, [
            self::OPT_BUFFER_WRITES,
            self::OPT_HASH_WITH_PREFIX_KEY,
            self::OPT_LIBKETAMA_COMPATIBLE,
            self::OPT_NO_BLOCK,
            self::OPT_NOREPLY,
            self::OPT_TCP_KEEPALIVE,
            self::OPT_TCP_NODELAY,
            self::OPT_VERIFY_KEY,
        ], true);
    }

    /**
     * php-memcached coerces bucket map values to integers and rejects only negative values.
     *
     * @param array<mixed> $map
     */
    private function bucketMapValuesAreValid(array $map): bool
    {
        foreach ($map as $value) {
            if ($this->bucketMapValueToInt($value) < 0) {
                return false;
            }
        }

        return true;
    }

    private function bucketMapValueToInt(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value) || \is_bool($value)) {
            return (int) $value;
        }

        if (\is_string($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * @param array<mixed> $keys
     *
     * @return list<string>
     */
    private function keyStrings(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[] = $this->keyToString($key);
        }

        return $out;
    }

    private function ensureServersAvailable(): bool
    {
        if ([] !== $this->core->selector->getServers()) {
            return true;
        }

        $this->setResult(self::RES_NO_SERVERS);

        return false;
    }

    private function recordServerFailure(?int $serverIndex, \Throwable $throwable): void
    {
        $this->core->lastErrorErrno = $throwable->getCode();
        if (null === $serverIndex) {
            return;
        }

        $server = $this->core->selector->getServers()[$serverIndex] ?? null;
        if (null === $server) {
            return;
        }

        $this->core->lastDisconnectedServer = [
            'host' => $server['host'],
            'port' => $server['port'],
            'weight' => $server['weight'],
            'type' => 'TCP',
        ];
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

    private function flushNetworkWrites(): void
    {
        $this->core->conn->flushAllBuffers();
    }

    private function useNoReply(): bool
    {
        return true === ($this->core->options[self::OPT_NOREPLY] ?? false);
    }

    private function shouldBufferNoReplyWrite(): bool
    {
        return $this->useNoReply() && true === ($this->core->options[self::OPT_BUFFER_WRITES] ?? false);
    }
}
