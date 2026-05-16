<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\CacheClient;
use PureCache\MemcachedConstants;

/**
 * PECL {@code cache_cb} fallback when {@code get()} misses.
 */
final readonly class ClientCacheCallbackInvoker
{
    /**
     * @param \Closure(string, mixed, int): bool         $set
     * @param \Closure(string, int): mixed               $get
     * @param \Closure(string, string, mixed, int): bool $setByKey
     * @param \Closure(string, string, int): mixed       $getByKey
     */
    public function __construct(
        private ClientCoordinatorEnv $env,
        private CacheClient $client,
        private \Closure $set,
        private \Closure $get,
        private \Closure $setByKey,
        private \Closure $getByKey,
    ) {
    }

    /**
     * @param callable(CacheClient, string, mixed, int, float):bool $cacheCb
     */
    public function invoke(callable $cacheCb, string $key, ?string $serverKey, int $getFlags): mixed
    {
        /** @var mixed $value filled by-ref by the PECL cache_cb signature */
        $value = null;
        $expirationRef = 0;
        $casRef = 0.0;
        $accepted = $cacheCb($this->client, $key, $value, $expirationRef, $casRef);
        if (true !== $accepted || null === $value) {
            $this->env->setResult(MemcachedConstants::RES_NOTFOUND);

            return false;
        }

        $expiration = ClientInputCoercion::normalizeCacheCbExpiration($expirationRef);

        if (null === $serverKey) {
            ($this->set)($key, $value, $expiration);

            return ($this->get)($key, $getFlags);
        }

        ($this->setByKey)($serverKey, $key, $value, $expiration);

        return ($this->getByKey)($serverKey, $key, $getFlags);
    }
}
