<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientObserverNotifier;
use PureCache\Memcached\Internal\MemcachedClientCore;
use PureCache\MemcachedConstants;
use PureCache\Tests\Unit\PureCache\RecordingClientObserver;

final class ClientObserverNotifierTest extends TestCase
{
    public function testNotifyItemTooBigInvokesObserver(): void
    {
        $core = MemcachedClientCore::createFresh();
        $observer = new RecordingClientObserver();
        $core->observer = $observer;

        ClientObserverNotifier::notifyItemTooBig($core, 'k', 4096);

        self::assertContains('e2big:k:4096', $observer->events);
    }

    public function testNotifyOperationFailureSkipsSuccessAndEmptyOperation(): void
    {
        $core = MemcachedClientCore::createFresh();
        $observer = new RecordingClientObserver();
        $core->observer = $observer;

        ClientObserverNotifier::notifyOperationFailure($core, 'get', MemcachedConstants::RES_SUCCESS, 'k');
        ClientObserverNotifier::notifyOperationFailure($core, '', MemcachedConstants::RES_FAILURE, 'k');
        ClientObserverNotifier::notifyOperationFailure($core, 'get', MemcachedConstants::RES_NOTFOUND, 'k');

        self::assertSame(['op:get:16:k'], $observer->events);
    }
}
