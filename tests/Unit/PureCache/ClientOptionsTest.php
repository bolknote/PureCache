<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientOptions;
use PureCache\Memcached\MemcachedClient;
use PureCache\MemcachedConstants;

/**
 * The default {@code OPT_SERIALIZER} is the only option whose default is not
 * static — it follows PECL's documented "igbinary if available, then msgpack,
 * then PHP" precedence so that swapping ext-memcached for PureCache doesn't
 * silently downgrade the wire format. Tests live here so the contract is
 * exercised without a memcached server (the parity suite double-checks against
 * the real C extension separately).
 */
final class ClientOptionsTest extends TestCase
{
    public function testDefaultSerializerFollowsPeclPrecedence(): void
    {
        $expected = match (true) {
            \extension_loaded('igbinary') => MemcachedConstants::SERIALIZER_IGBINARY,
            \extension_loaded('msgpack') => MemcachedConstants::SERIALIZER_MSGPACK,
            default => MemcachedConstants::SERIALIZER_PHP,
        };

        self::assertSame($expected, ClientOptions::defaultSerializer());
    }

    public function testDefaultsArrayUsesPeclSerializerPrecedence(): void
    {
        $defaults = ClientOptions::defaults();

        self::assertArrayHasKey(MemcachedConstants::OPT_SERIALIZER, $defaults);
        self::assertSame(ClientOptions::defaultSerializer(), $defaults[MemcachedConstants::OPT_SERIALIZER]);
    }

    public function testFreshMemcachedClientReportsPeclStyleDefaultSerializer(): void
    {
        $client = new MemcachedClient();

        self::assertSame(
            ClientOptions::defaultSerializer(),
            $client->getOption(MemcachedClient::OPT_SERIALIZER),
        );
    }

    public function testDefaultSerializerIsOneOfTheDocumentedValues(): void
    {
        self::assertContains(ClientOptions::defaultSerializer(), [
            MemcachedConstants::SERIALIZER_IGBINARY,
            MemcachedConstants::SERIALIZER_MSGPACK,
            MemcachedConstants::SERIALIZER_PHP,
        ]);
    }
}
