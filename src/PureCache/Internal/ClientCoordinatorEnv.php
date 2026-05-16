<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * Shared dependencies for {@see ClientWriteCoordinator},
 * {@see ClientRoutingCoordinator}, {@see ClientHealthRecorder}, and
 * {@see ClientKeyedExecutor} without coupling them to
 * {@see \PureCache\AbstractCacheClient}.
 */
final readonly class ClientCoordinatorEnv
{
    /**
     * @param \Closure(int, ?string=): void $setResult
     * @param \Closure(): int               $getResultCode
     * @param \Closure(int, int): int       $optionInt
     * @param \Closure(int, bool): bool     $optionBool
     * @param \Closure(string): string      $prefixedKey
     * @param \Closure(string): string      $routingKey
     * @param \Closure(string): bool        $checkKeyInternal
     */
    public function __construct(public ClientCoreState $core, private \Closure $setResult, private \Closure $getResultCode, private \Closure $optionInt, private \Closure $optionBool, private \Closure $prefixedKey, private \Closure $routingKey, private \Closure $checkKeyInternal)
    {
    }

    public function setResult(int $code, ?string $message = null): void
    {
        ($this->setResult)($code, $message);
    }

    public function getResultCode(): int
    {
        return ($this->getResultCode)();
    }

    public function optionInt(int $option, int $default): int
    {
        return ($this->optionInt)($option, $default);
    }

    public function optionBool(int $option, bool $default): bool
    {
        return ($this->optionBool)($option, $default);
    }

    public function prefixedKey(string $key): string
    {
        return ($this->prefixedKey)($key);
    }

    public function routingKey(string $itemKey): string
    {
        return ($this->routingKey)($itemKey);
    }

    public function checkKeyInternal(string $key): bool
    {
        return ($this->checkKeyInternal)($key);
    }
}
