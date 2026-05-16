<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\Expiration;

final class ExpirationTest extends TestCase
{
    public function testCannotInstantiateExpirationDirectly(): void
    {
        $reflection = new \ReflectionClass(Expiration::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        $constructor->invoke($instance);
    }
}
