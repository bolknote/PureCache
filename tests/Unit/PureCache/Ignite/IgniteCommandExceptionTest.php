<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Ignite;

use PHPUnit\Framework\TestCase;
use PureCache\Ignite\IgniteCommandException;

final class IgniteCommandExceptionTest extends TestCase
{
    public function testExposesIgniteStatusCode(): void
    {
        $exception = new IgniteCommandException('cache not found', 1001);

        self::assertSame('cache not found', $exception->getMessage());
        self::assertSame(1001, $exception->statusCode);
        self::assertSame(1001, $exception->getCode());
    }
}
