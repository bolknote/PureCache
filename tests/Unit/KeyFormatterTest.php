<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\KeyFormatter;
use PureCache\MemcachedConstants;

/**
 * Behaviours of {@see KeyFormatter} that aren't exercised by other test files:
 * the lax {@code isValid} variant used when {@code OPT_VERIFY_KEY} is disabled,
 * and defensive handling of non-scalar prefix option values.
 */
final class KeyFormatterTest extends TestCase
{
    public function testIsValidStrictRejectsKeysWithSpacesAndControlChars(): void
    {
        self::assertFalse(KeyFormatter::isValid('bad key'));
        self::assertFalse(KeyFormatter::isValid("ctl\x01char"));
        self::assertFalse(KeyFormatter::isValid(''));
        self::assertFalse(KeyFormatter::isValid(str_repeat('a', 251)));
    }

    public function testIsValidNonStrictOnlyEnforcesLengthBounds(): void
    {
        // With OPT_VERIFY_KEY off, callers can ship binary keys over the meta
        // protocol via the `b` token. Only length is policed.
        self::assertTrue(KeyFormatter::isValid('bad key', false));
        self::assertTrue(KeyFormatter::isValid("binary\0key", false));
        self::assertTrue(KeyFormatter::isValid(str_repeat('a', 250), false));

        self::assertFalse(KeyFormatter::isValid('', false));
        self::assertFalse(KeyFormatter::isValid(str_repeat('a', 251), false));
    }

    public function testPrefixedCastsScalarPrefixOptionAndIgnoresNonScalars(): void
    {
        // Scalar prefixes (string/int/null) coerce to a string prefix.
        self::assertSame('42item', KeyFormatter::prefixed('item', [
            MemcachedConstants::OPT_PREFIX_KEY => 42,
        ]));
        self::assertSame('item', KeyFormatter::prefixed('item', [
            MemcachedConstants::OPT_PREFIX_KEY => null,
        ]));

        // Non-scalar prefix options (e.g. a stray array left by an app bug)
        // must be ignored, otherwise we'd crash inside the key formatter and
        // brick every read/write done after misconfiguration.
        self::assertSame('item', KeyFormatter::prefixed('item', [
            MemcachedConstants::OPT_PREFIX_KEY => ['unexpected'],
        ]));
        self::assertSame('item', KeyFormatter::routing('item', [
            MemcachedConstants::OPT_PREFIX_KEY => new \stdClass(),
            MemcachedConstants::OPT_HASH_WITH_PREFIX_KEY => true,
        ]));
    }
}
