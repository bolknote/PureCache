<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * PECL-shaped server list management ({@code addServer}, {@code addServers},
 * {@code getServerList}, {@code setBucket}, …).
 *
 * @psalm-suppress MixedAssignment
 */
final readonly class ClientServerRegistry
{
    /**
     * @param \Closure(): void       $onPoolInvalidated
     * @param \Closure(): int        $defaultPort
     * @param \Closure(string): bool $checkServerKey
     */
    public function __construct(
        private ClientCoordinatorEnv $env,
        private \Closure $onPoolInvalidated,
        private \Closure $defaultPort,
        private \Closure $checkServerKey,
    ) {
    }

    public function addServer(string $host, int $port = 0, int $weight = 0): bool
    {
        if (str_contains($host, '://')) {
            $servers = ConnectionStringParser::parseServers($host);
            if ([] === $servers) {
                $this->env->setResult(MemcachedConstants::RES_FAILURE, 'invalid server entry');

                return false;
            }

            if (1 === \count($servers) && 0 !== $port) {
                $servers[0]['port'] = $port;
            }

            if (1 === \count($servers) && 0 !== $weight) {
                $servers[0]['weight'] = $weight;
            }

            return $this->addServers($servers);
        }

        if ('' === $host) {
            $host = 'localhost';
        }

        if (0 === $port) {
            $port = ($this->defaultPort)();
        }

        if ($port < 0) {
            $this->env->setResult(MemcachedConstants::RES_INVALID_ARGUMENTS);

            return false;
        }

        $this->env->core->selector->addServer(['host' => $host, 'port' => $port, 'weight' => $weight]);
        ($this->onPoolInvalidated)();
        $this->env->setResult(MemcachedConstants::RES_SUCCESS);

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
                if (array_is_list($s) && isset($s[0], $s[1])) {
                    $w = $s[2] ?? 0;
                    $validated[] = ['host' => ClientInputCoercion::coerceString($s[0]), 'port' => ClientInputCoercion::coerceInt($s[1]), 'weight' => ClientInputCoercion::coerceInt($w)];
                    continue;
                }

                if (isset($s['host'], $s['port'])) {
                    $w = $s['weight'] ?? 0;
                    $entry = [
                        'host' => ClientInputCoercion::coerceString($s['host']),
                        'port' => ClientInputCoercion::coerceInt($s['port']),
                        'weight' => ClientInputCoercion::coerceInt($w),
                    ];
                    if (isset($s['user'])) {
                        $entry['user'] = ClientInputCoercion::coerceString($s['user']);
                    }

                    if (isset($s['password'])) {
                        $entry['password'] = ClientInputCoercion::coerceString($s['password']);
                    }

                    if (isset($s['database'])) {
                        $entry['database'] = ClientInputCoercion::coerceInt($s['database']);
                    }

                    if (isset($s['tls'])) {
                        $entry['tls'] = (bool) $s['tls'];
                    }

                    if (isset($s['tls_ca_file'])) {
                        $entry['tls_ca_file'] = ClientInputCoercion::coerceString($s['tls_ca_file']);
                    }

                    $validated[] = $entry;
                    continue;
                }
            }

            $this->env->setResult(MemcachedConstants::RES_FAILURE, 'invalid server entry');

            return false;
        }

        foreach ($validated as $server) {
            $this->env->core->selector->addServer($server);
        }

        ($this->onPoolInvalidated)();
        $this->env->setResult(MemcachedConstants::RES_SUCCESS);

        return true;
    }

    /**
     * @return list<array{host:string,port:int,type:string,weight:int}>
     */
    public function getServerList(): array
    {
        $out = [];
        foreach ($this->env->core->selector->getServers() as $s) {
            $out[] = ['host' => $s['host'], 'port' => $s['port'], 'type' => ServerEndpoint::listType($s['host']), 'weight' => $s['weight']];
        }

        return $out;
    }

    /**
     * @return array{host:string,port:int,weight:int}|false
     */
    public function getServerByKey(string $serverKey): array|false
    {
        if (!($this->checkServerKey)($serverKey)) {
            $this->env->setResult(MemcachedConstants::RES_BAD_KEY_PROVIDED);

            return false;
        }

        if ([] === $this->env->core->selector->getServers()) {
            $this->env->setResult(MemcachedConstants::RES_NO_SERVERS);

            return false;
        }

        $idx = $this->env->core->selector->pickServerIndex($serverKey);
        $s = $this->env->core->selector->getServers()[$idx];
        $this->env->setResult(MemcachedConstants::RES_SUCCESS);

        return ['host' => $s['host'], 'port' => $s['port'], 'weight' => $s['weight']];
    }

    public function resetServerList(): bool
    {
        $this->env->core->selector->reset();
        ($this->onPoolInvalidated)();
        $this->env->setResult(MemcachedConstants::RES_SUCCESS);

        return true;
    }

    /**
     * @param array<mixed>      $hostMap
     * @param array<mixed>|null $forwardMap
     */
    public function setBucket(array $hostMap, ?array $forwardMap, int $replicas): bool
    {
        if ([] === $hostMap) {
            trigger_error('Memcached::setBucket(): server map cannot be empty', \E_USER_WARNING);
            $this->env->setResult(MemcachedConstants::RES_INVALID_ARGUMENTS);

            return false;
        }

        if (null !== $forwardMap && \count($forwardMap) !== \count($hostMap)) {
            trigger_error('Memcached::setBucket(): forward_map length must match the server_map length', \E_USER_WARNING);
            $this->env->setResult(MemcachedConstants::RES_INVALID_ARGUMENTS);

            return false;
        }

        if ($replicas < 0) {
            trigger_error('Memcached::setBucket(): replicas must be larger than zero', \E_USER_WARNING);
            $this->env->setResult(MemcachedConstants::RES_INVALID_ARGUMENTS);

            return false;
        }

        if (!ClientInputCoercion::bucketMapValuesAreValid($hostMap) || (null !== $forwardMap && !ClientInputCoercion::bucketMapValuesAreValid($forwardMap))) {
            trigger_error('Memcached::setBucket(): the map must contain positive integers', \E_USER_WARNING);
            $this->env->setResult(MemcachedConstants::RES_INVALID_ARGUMENTS);

            return false;
        }

        $fwd = null !== $forwardMap ? array_map(ClientInputCoercion::coerceInt(...), array_values($forwardMap)) : null;
        $this->env->core->selector->setBucket(array_map(ClientInputCoercion::coerceInt(...), array_values($hostMap)), $replicas, $fwd);
        ($this->onPoolInvalidated)();
        $this->env->setResult(MemcachedConstants::RES_SUCCESS);

        return true;
    }
}
