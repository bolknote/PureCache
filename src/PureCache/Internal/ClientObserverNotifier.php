<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Dispatches optional {@see ClientObserver} callbacks from shared infrastructure.
 */
final class ClientObserverNotifier
{
    private function __construct()
    {
    }

    public static function notifyItemTooBig(ClientCoreState $core, ?string $key, int $bytes): void
    {
        $observer = $core->observer;
        if (!$observer instanceof ClientObserver) {
            return;
        }

        $observer->onItemTooBig($key, $bytes);
    }

    public static function notifyOperationFailure(
        ClientCoreState $core,
        string $operation,
        int $resultCode,
        ?string $key = null,
    ): void {
        if (MemcachedConstants::RES_SUCCESS === $resultCode || '' === $operation) {
            return;
        }

        $observer = $core->observer;
        if (!$observer instanceof ClientObserver) {
            return;
        }

        $observer->onOperationFailure($operation, $resultCode, $key);
    }
}
