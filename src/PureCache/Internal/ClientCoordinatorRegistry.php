<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * Lazy coordinator graph for {@see \PureCache\AbstractCacheClient}.
 */
final class ClientCoordinatorRegistry
{
    private ?ClientCoordinatorEnv $env = null;

    private ?ClientHealthRecorder $health = null;

    private ?ClientRoutingCoordinator $routing = null;

    private ?ClientWriteCoordinator $write = null;

    private ?ClientKeyedExecutor $keyed = null;

    private ?ClientPoolCoordinator $pool = null;

    private ?ClientStoreEncoder $storeEncoder = null;

    private ?ClientServerRegistry $serverRegistry = null;

    private ?ClientKeyHelper $keyHelper = null;

    private ?ClientDeleteCoordinator $delete = null;

    private ?ClientStoreMultiCoordinator $storeMulti = null;

    private ?ClientCacheCallbackInvoker $cacheCallback = null;

    private ?ClientReadCoordinator $read = null;

    private ?ClientDelayedFetchCoordinator $delayedFetch = null;

    private ?ClientMultiKeyCoordinator $multiKey = null;

    private ?ClientOptionAccessor $options = null;

    private ?ClientEncodingConfigurator $encoding = null;

    private ?ClientLifecycleHelper $lifecycle = null;

    public function __construct(private readonly ClientCoordinatorBinding $binding)
    {
    }

    public function env(): ClientCoordinatorEnv
    {
        return $this->env ??= new ClientCoordinatorEnv(
            $this->binding->core,
            $this->binding->setResult,
            $this->binding->getResultCode,
            $this->binding->optionInt,
            $this->binding->optionBool,
            $this->binding->prefixedKey,
            $this->binding->routingKey,
            $this->binding->checkKeyInternal,
        );
    }

    public function health(): ClientHealthRecorder
    {
        return $this->health ??= new ClientHealthRecorder($this->env());
    }

    public function routing(): ClientRoutingCoordinator
    {
        return $this->routing ??= new ClientRoutingCoordinator($this->env());
    }

    public function write(): ClientWriteCoordinator
    {
        return $this->write ??= new ClientWriteCoordinator(
            $this->env(),
            $this->routing(),
            $this->health(),
        );
    }

    public function keyed(): ClientKeyedExecutor
    {
        return $this->keyed ??= new ClientKeyedExecutor(
            $this->env(),
            $this->routing(),
            $this->health(),
        );
    }

    public function pool(): ClientPoolCoordinator
    {
        return $this->pool ??= new ClientPoolCoordinator(
            $this->env(),
            $this->health(),
        );
    }

    public function storeEncoder(): ClientStoreEncoder
    {
        return $this->storeEncoder ??= new ClientStoreEncoder(
            $this->env(),
            $this->binding->encodingContext,
        );
    }

    public function serverRegistry(): ClientServerRegistry
    {
        return $this->serverRegistry ??= new ClientServerRegistry(
            $this->env(),
            $this->binding->onPoolInvalidated,
            $this->binding->defaultPort,
            $this->binding->checkKeyInternal,
        );
    }

    public function keyHelper(): ClientKeyHelper
    {
        return $this->keyHelper ??= new ClientKeyHelper($this->env());
    }

    public function delete(): ClientDeleteCoordinator
    {
        return $this->delete ??= new ClientDeleteCoordinator(
            $this->env(),
            $this->routing(),
            $this->binding->prefixedKey,
        );
    }

    public function storeMulti(): ClientStoreMultiCoordinator
    {
        return $this->storeMulti ??= new ClientStoreMultiCoordinator(
            $this->env(),
            $this->routing(),
            $this->binding->keyToString,
            $this->binding->prefixedKey,
        );
    }

    public function cacheCallback(): ClientCacheCallbackInvoker
    {
        return $this->cacheCallback ??= new ClientCacheCallbackInvoker(
            $this->env(),
            $this->binding->cacheClient,
            $this->binding->setForCacheCb,
            $this->binding->getForCacheCb,
            $this->binding->setByKeyForCacheCb,
            $this->binding->getByKeyForCacheCb,
        );
    }

    public function read(): ClientReadCoordinator
    {
        return $this->read ??= new ClientReadCoordinator(
            $this->env(),
            $this->routing(),
            $this->binding->flushNetworkWrites,
            $this->binding->doGet,
            fn (callable $cacheCb, string $key, ?string $serverKey, int $getFlags): mixed => $this->cacheCallback()->invoke($cacheCb, $key, $serverKey, $getFlags),
        );
    }

    public function delayedFetch(): ClientDelayedFetchCoordinator
    {
        return $this->delayedFetch ??= new ClientDelayedFetchCoordinator(
            $this->env(),
            $this->routing(),
            $this->binding->flushNetworkWrites,
            $this->binding->keyToString,
            $this->binding->keyStrings,
            $this->binding->doGetDelayedValueCallback,
            $this->binding->doFetchBatch,
        );
    }

    public function multiKey(): ClientMultiKeyCoordinator
    {
        return $this->multiKey ??= new ClientMultiKeyCoordinator(
            $this->env(),
            $this->binding->flushNetworkWrites,
            $this->binding->keyStrings,
            $this->binding->keyToString,
            $this->binding->checkKeyInternal,
            $this->binding->prefixedKey,
            $this->binding->ensureServersAvailable,
            $this->binding->doGetMulti,
            $this->binding->doDelete,
            fn (int $time): bool => $this->delete()->acceptDeleteTime($time),
        );
    }

    public function options(): ClientOptionAccessor
    {
        return $this->options ??= new ClientOptionAccessor(
            $this->env(),
            $this->binding->options,
        );
    }

    public function encoding(): ClientEncodingConfigurator
    {
        return $this->encoding ??= new ClientEncodingConfigurator($this->env());
    }

    public function lifecycle(): ClientLifecycleHelper
    {
        return $this->lifecycle ??= new ClientLifecycleHelper(
            $this->env(),
            $this->binding->onPoolInvalidated,
            $this->binding->flushNetworkWrites,
        );
    }
}
