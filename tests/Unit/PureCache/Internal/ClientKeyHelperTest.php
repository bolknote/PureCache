<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientKeyHelper;

final class ClientKeyHelperTest extends TestCase
{
    public function testCasValueTreatsMissingTokenAsZero(): void
    {
        self::assertSame(0, ClientKeyHelper::casValue(null));
        self::assertSame(0, ClientKeyHelper::casValue(''));
    }

    public function testCasValuePreservesNonNumericTokens(): void
    {
        self::assertSame('deadbeef', ClientKeyHelper::casValue('deadbeef'));
    }
}
