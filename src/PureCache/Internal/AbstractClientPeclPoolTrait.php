<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * PECL pool / options / result-code surface for {@see \PureCache\AbstractCacheClient}.
 *
 * @phpstan-require-extends \PureCache\AbstractCacheClient
 */
trait AbstractClientPeclPoolTrait
{
    abstract protected function coordinators(): ClientCoordinatorRegistry;

    abstract protected function setResult(int $code, ?string $message = null): void;

    abstract protected function prefixedKey(string $key): string;

    #[\Override]
    public function getResultCode(): int
    {
        return $this->core->resultCode;
    }

    #[\Override]
    public function getResultMessage(): string
    {
        return $this->core->resultMessage;
    }

    public function setClientObserver(?ClientObserver $observer): void
    {
        $this->core->observer = $observer;
    }

    public function getClientObserver(): ?ClientObserver
    {
        return $this->core->observer;
    }

    #[\Override]
    public function addServer(string $host, int $port = 0, int $weight = 0): bool
    {
        return $this->coordinators()->serverRegistry()->addServer($host, $port, $weight);
    }

    /**
     * @param array<mixed> $servers
     */
    #[\Override]
    public function addServers(array $servers): bool
    {
        return $this->coordinators()->serverRegistry()->addServers($servers);
    }

    /**
     * @return list<array{host:string,port:int,type:string,weight:int}>
     */
    #[\Override]
    public function getServerList(): array
    {
        return $this->coordinators()->serverRegistry()->getServerList();
    }

    /**
     * @return array{host:string,port:int,weight:int}|false
     */
    #[\Override]
    public function getServerByKey(string $server_key): array|false
    {
        return $this->coordinators()->serverRegistry()->getServerByKey($server_key);
    }

    #[\Override]
    public function resetServerList(): bool
    {
        return $this->coordinators()->serverRegistry()->resetServerList();
    }

    /**
     * @param array<mixed>      $host_map
     * @param array<mixed>|null $forward_map
     */
    #[\Override]
    public function setBucket(array $host_map, ?array $forward_map, int $replicas): bool
    {
        return $this->coordinators()->serverRegistry()->setBucket($host_map, $forward_map, $replicas);
    }

    #[\Override]
    public function quit(): bool
    {
        return $this->coordinators()->lifecycle()->quit();
    }

    #[\Override]
    public function flushBuffers(): bool
    {
        return $this->coordinators()->lifecycle()->flushBuffers();
    }

    #[\Override]
    public function getLastErrorMessage(): string
    {
        return $this->core->resultMessage;
    }

    #[\Override]
    public function getLastErrorCode(): int
    {
        return $this->core->resultCode;
    }

    #[\Override]
    public function getLastErrorErrno(): int
    {
        return $this->core->lastErrorErrno;
    }

    /**
     * @return array{host:string,port:int,weight:int,type:string}|false
     */
    #[\Override]
    public function getLastDisconnectedServer(): array|false
    {
        return $this->core->lastDisconnectedServer ?? false;
    }

    #[\Override]
    public function getOption(int $option): mixed
    {
        return $this->coordinators()->options()->get($option);
    }

    #[\Override]
    public function setOption(int $option, mixed $value): bool
    {
        return $this->coordinators()->options()->set($option, $value);
    }

    /**
     * @param array<mixed> $options
     */
    #[\Override]
    public function setOptions(array $options): bool
    {
        return $this->coordinators()->options()->setMany($options);
    }

    #[\Override]
    public function isPersistent(): bool
    {
        return null !== $this->persistentId && '' !== $this->persistentId;
    }

    #[\Override]
    public function isPristine(): bool
    {
        return $this->pristine;
    }

    #[\Override]
    public function checkKey(string $key): bool
    {
        return $this->checkKeyInternal($this->prefixedKey($key));
    }

    #[\Override]
    public function setEncodingKey(#[\SensitiveParameter] string $key): bool
    {
        return $this->coordinators()->encoding()->setEncodingKey($key);
    }

    #[\Override]
    public function setSaslAuthData(string $username, #[\SensitiveParameter] string $password): bool
    {
        $this->setResult(\PureCache\MemcachedConstants::RES_NOT_SUPPORTED);

        return false;
    }

    /**
     * @return array<string, mixed>|false
     */
    #[\Override]
    public function getStats(?string $type = null): array|false
    {
        return $this->doGetStats($type);
    }

    /**
     * @return array<string, string>|false
     */
    #[\Override]
    public function getVersion(): array|false
    {
        return $this->doGetVersion();
    }

    #[\Override]
    public function flush(int $delay = 0): bool
    {
        return $this->doFlush($delay);
    }

    /**
     * @return list<string>|false
     */
    #[\Override]
    public function getAllKeys(): array|false
    {
        return $this->doGetAllKeys();
    }
}
