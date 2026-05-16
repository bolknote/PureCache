<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureCache\Memcached\Internal\MetaCommandBuilder;

/**
 * Pin down the exact wire shape we send to memcached's meta protocol. The
 * server interprets J/N as auto-create initial value and auto-create TTL —
 * leaving them out silently downgrades PECL's
 * {@code increment($key, 1, 0, 60)} to "not found" on a missing key.
 */
final class MetaCommandBuilderTest extends TestCase
{
    public function testIncrementWithoutAutoCreateOmitsJAndN(): void
    {
        $cmd = MetaCommandBuilder::metaArith('counter', 2, false);

        self::assertStringStartsWith('ma counter D2 MI v', $cmd);
        self::assertStringEndsWith("\r\n", $cmd);
        self::assertStringNotContainsString(' J', $cmd);
        self::assertStringNotContainsString(' N', $cmd);
    }

    public function testIncrementWithAutoCreatePassesNAndJ(): void
    {
        $cmd = MetaCommandBuilder::metaArith('counter', 1, false, 0, 60);

        self::assertSame("ma counter D1 MI v N60 J0\r\n", $cmd);
    }

    public function testDecrementWithAutoCreate(): void
    {
        $cmd = MetaCommandBuilder::metaArith('hits', 3, true, 100, 0);

        self::assertSame("ma hits D3 MD v N0 J100\r\n", $cmd);
    }

    public function testBinaryFlagIsPreservedAlongsideAutoCreate(): void
    {
        $cmd = MetaCommandBuilder::metaArith('binary key', 1, false, 0, 0);

        self::assertStringContainsString(' b', $cmd);
        self::assertStringContainsString(' J0', $cmd);
        self::assertStringContainsString(' N0', $cmd);
    }

    public function testMetaGetValueRequestsValueFlagsTtlAndCasFlags(): void
    {
        self::assertSame("mg user_42 v f t c\r\n", MetaCommandBuilder::metaGetValue('user_42'));
    }

    public function testMetaGetValueEncodesBinaryKeyWithBFlag(): void
    {
        $cmd = MetaCommandBuilder::metaGetValue("user\x01 42");

        // The "v f t c" trailing token order is positional, the b flag must come after them so the server reads it as a flag, not a key.
        self::assertSame('mg '.base64_encode("user\x01 42")." v f t c b\r\n", $cmd);
    }

    public function testMetaGetTouchEmitsTtlTokenAndCrlf(): void
    {
        self::assertSame("mg session T60\r\n", MetaCommandBuilder::metaGetTouch('session', '60'));
    }

    public function testMetaGetTouchAppendsBFlagForNonPrintableKey(): void
    {
        $cmd = MetaCommandBuilder::metaGetTouch("ses\x00ion", '0');

        self::assertSame('mg '.base64_encode("ses\x00ion")." T0 b\r\n", $cmd);
    }

    public function testMetaGetTouchHonoursNoReplyFlag(): void
    {
        self::assertSame("mg session T60 q\r\n", MetaCommandBuilder::metaGetTouch('session', '60', true));
    }

    public function testMetaStoreEmitsLengthFlagsAndPayloadCrlf(): void
    {
        $payload = "hello\r\nworld";
        $cmd = MetaCommandBuilder::metaStore('cache_key', $payload, 7, '30', 'S');

        self::assertSame(
            'ms cache_key '.\strlen($payload)." T30 F7 MS\r\n".$payload."\r\n",
            $cmd,
        );
    }

    public function testMetaStorePassesExtraFlagTokensInOrderAndAppendsBLast(): void
    {
        $cmd = MetaCommandBuilder::metaStore("bin\x10key", 'v', 0, '0', 'E', ['q', 'C123']);

        self::assertSame(
            'ms '.base64_encode("bin\x10key")." 1 T0 F0 ME q C123 b\r\nv\r\n",
            $cmd,
        );
    }

    public function testMetaDeleteEmitsBareCommandWithoutFlags(): void
    {
        self::assertSame("md key\r\n", MetaCommandBuilder::metaDelete('key', false));
    }

    public function testMetaDeleteAppendsNoReplyFlag(): void
    {
        self::assertSame("md key q\r\n", MetaCommandBuilder::metaDelete('key', true));
    }

    public function testMetaDeleteEncodesBinaryKeyAndCombinesWithNoReply(): void
    {
        $cmd = MetaCommandBuilder::metaDelete("k\x02y", true);

        self::assertSame('md '.base64_encode("k\x02y")." b q\r\n", $cmd);
    }
}
