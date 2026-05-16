<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientInputCoercion;

final class ClientInputCoercionTest extends TestCase
{
    public function testCoerceStringMapsScalarsAndRejectsObjects(): void
    {
        self::assertSame('42', ClientInputCoercion::coerceString(42));
        self::assertSame('', ClientInputCoercion::coerceString(['not-scalar']));
    }

    public function testCoerceIntMapsNumericForms(): void
    {
        self::assertSame(7, ClientInputCoercion::coerceInt(7));
        self::assertSame(1, ClientInputCoercion::coerceInt(true));
        self::assertSame(3, ClientInputCoercion::coerceInt('3'));
        self::assertSame(0, ClientInputCoercion::coerceInt(['x']));
    }

    public function testNormalizeCacheCbExpirationCoercesNumericStrings(): void
    {
        self::assertSame(90, ClientInputCoercion::normalizeCacheCbExpiration('90'));
        self::assertSame(0, ClientInputCoercion::normalizeCacheCbExpiration(['bad']));
    }

    public function testBucketMapValuesAreValid(): void
    {
        self::assertTrue(ClientInputCoercion::bucketMapValuesAreValid(['a' => 1, 'b' => 0]));
        self::assertFalse(ClientInputCoercion::bucketMapValuesAreValid(['a' => -1]));
    }
}
