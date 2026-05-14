<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\EncodingContext;
use PureCache\MemcachedConstants;

/**
 * Direct coverage for {@see EncodingContext::fromUserKey()}.
 *
 * The public client surface ({@see \PureCache\AbstractCacheClient::setEncodingKey()})
 * routes empty keys and unknown modes through this factory and relies on a
 * {@code null} return to surface {@code RES_INVALID_ARGUMENTS}. Keeping the
 * factory's contract pinned in isolation guards against silent regressions
 * where, e.g. a future refactor returns an empty-keyed instance instead of
 * {@code null}.
 */
final class EncodingContextTest extends TestCase
{
    public function testEmptyUserKeyReturnsNull(): void
    {
        self::assertNull(EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_LIBMEMCACHED, ''));
        self::assertNull(EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_AEAD, ''));
    }

    public function testUnknownModeReturnsNull(): void
    {
        self::assertNull(EncodingContext::fromUserKey(-1, 'pass'));
        self::assertNull(EncodingContext::fromUserKey(999, 'pass'));
    }

    public function testLibmemcachedModeDerivesSixteenByteMd5Key(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_LIBMEMCACHED, 'passphrase');
        self::assertNotNull($ctx);

        self::assertSame(MemcachedConstants::ENCODING_MODE_LIBMEMCACHED, $ctx->mode);
        self::assertSame(16, \strlen($ctx->keyBytes), 'AES-128 key is 16 bytes');
        self::assertSame(md5('passphrase', true), $ctx->keyBytes);
    }

    public function testAeadModeDerivesThirtyTwoByteSha256Key(): void
    {
        $ctx = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_AEAD, 'passphrase');
        self::assertNotNull($ctx);

        self::assertSame(MemcachedConstants::ENCODING_MODE_AEAD, $ctx->mode);
        self::assertSame(32, \strlen($ctx->keyBytes), 'AES-256 key is 32 bytes');
        self::assertSame(hash('sha256', 'passphrase', true), $ctx->keyBytes);
    }

    public function testSameUserKeyProducesSameDerivedBytes(): void
    {
        $a = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_AEAD, 'shared');
        $b = EncodingContext::fromUserKey(MemcachedConstants::ENCODING_MODE_AEAD, 'shared');
        self::assertNotNull($a);
        self::assertNotNull($b);

        self::assertSame($a->keyBytes, $b->keyBytes);
    }
}
