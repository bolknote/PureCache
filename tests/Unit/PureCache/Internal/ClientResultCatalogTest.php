<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientResultCatalog;
use PureCache\MemcachedConstants;

final class ClientResultCatalogTest extends TestCase
{
    public function testDefaultMessageMapsKnownCodes(): void
    {
        self::assertSame('SUCCESS', ClientResultCatalog::defaultMessage(MemcachedConstants::RES_SUCCESS));
        self::assertSame('NOT FOUND', ClientResultCatalog::defaultMessage(MemcachedConstants::RES_NOTFOUND));
    }

    public function testDefaultMessageFallsBackToUnknown(): void
    {
        self::assertSame('UNKNOWN', ClientResultCatalog::defaultMessage(999_999));
    }
}
