<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\CacheClient;

/**
 * Callbacks from {@see \PureCache\AbstractCacheClient} into coordinator objects.
 */
final readonly class ClientCoordinatorBinding
{
    /**
     * @param \Closure(int, ?string=): void                                             $setResult
     * @param \Closure(): int                                                           $getResultCode
     * @param \Closure(int, int): int                                                   $optionInt
     * @param \Closure(int, bool): bool                                                 $optionBool
     * @param \Closure(string): string                                                  $prefixedKey
     * @param \Closure(string): string                                                  $routingKey
     * @param \Closure(string): bool                                                    $checkKeyInternal
     * @param \Closure(): void                                                          $onPoolInvalidated
     * @param \Closure(): void                                                          $flushNetworkWrites
     * @param \Closure(): int                                                           $defaultPort
     * @param \Closure(): ?EncodingContext                                              $encodingContext
     * @param \Closure(mixed): string                                                   $keyToString
     * @param \Closure(array<mixed>): list<string>                                      $keyStrings
     * @param \Closure(): bool                                                          $ensureServersAvailable
     * @param \Closure(string, string, ?string, int): mixed                             $doGet
     * @param \Closure(list<string>, ?string, int): (array<string, mixed>|false)        $doGetMulti
     * @param \Closure(string, ?string, int): bool                                      $doDelete
     * @param \Closure(list<string>, ?string, bool, callable): bool                     $doGetDelayedValueCallback
     * @param \Closure(list<string>, ?string, bool): (list<array<string, mixed>>|false) $doFetchBatch
     * @param \Closure(string, mixed, int): bool                                        $setForCacheCb
     * @param \Closure(string, int): mixed                                              $getForCacheCb
     * @param \Closure(string, string, mixed, int): bool                                $setByKeyForCacheCb
     * @param \Closure(string, string, int): mixed                                      $getByKeyForCacheCb
     */
    public function __construct(
        public ClientCoreState $core,
        public CacheClient $cacheClient,
        public OptionEnvironment $options,
        public \Closure $setResult,
        public \Closure $getResultCode,
        public \Closure $optionInt,
        public \Closure $optionBool,
        public \Closure $prefixedKey,
        public \Closure $routingKey,
        public \Closure $checkKeyInternal,
        public \Closure $onPoolInvalidated,
        public \Closure $flushNetworkWrites,
        public \Closure $defaultPort,
        public \Closure $encodingContext,
        public \Closure $keyToString,
        public \Closure $keyStrings,
        public \Closure $ensureServersAvailable,
        public \Closure $doGet,
        public \Closure $doGetMulti,
        public \Closure $doDelete,
        public \Closure $doGetDelayedValueCallback,
        public \Closure $doFetchBatch,
        public \Closure $setForCacheCb,
        public \Closure $getForCacheCb,
        public \Closure $setByKeyForCacheCb,
        public \Closure $getByKeyForCacheCb,
    ) {
    }
}
