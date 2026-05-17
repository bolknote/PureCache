<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\CacheClient;
use PureCache\Ignite\IgniteClient;
use PureCache\Internal\ClientOptions;
use PureCache\Internal\IniConfig;
use PureCache\Memcached\MemcachedClient;
use PureCache\MemcachedConstants;
use PureCache\Redis\RedisClient;

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
    public function testStringValueMapsNullToEmptyString(): void
    {
        self::assertSame('', ClientOptions::stringValue(null));
    }

    public function testStringValueRejectsNonScalarValues(): void
    {
        self::assertNull(ClientOptions::stringValue(['not', 'scalar']));
    }

    public function testDefaultSerializerFollowsPeclPrecedence(): void
    {
        $expected = match (true) {
            \extension_loaded('memcached') && \Memcached::HAVE_IGBINARY && ClientOptions::serializerIsUsable(MemcachedConstants::SERIALIZER_IGBINARY) => MemcachedConstants::SERIALIZER_IGBINARY,
            \extension_loaded('memcached') && \Memcached::HAVE_MSGPACK && ClientOptions::serializerIsUsable(MemcachedConstants::SERIALIZER_MSGPACK) => MemcachedConstants::SERIALIZER_MSGPACK,
            ClientOptions::serializerIsUsable(MemcachedConstants::SERIALIZER_IGBINARY) => MemcachedConstants::SERIALIZER_IGBINARY,
            ClientOptions::serializerIsUsable(MemcachedConstants::SERIALIZER_MSGPACK) => MemcachedConstants::SERIALIZER_MSGPACK,
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

    public function testFreshMemcachedClientReportsIniBackedDefaultSerializer(): void
    {
        $client = new MemcachedClient();

        // MemcachedClient applies memcached.* INI via IniConfig::snapshot() on
        // construct; that may differ from ClientOptions::defaultSerializer()
        // when php.ini pins memcached.serializer=php while ext-igbinary is loaded.
        self::assertSame(
            IniConfig::snapshot()['serializer'],
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

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function peclLongValueMatrix(): array
    {
        return [
            // ints (identity)
            'int_zero' => [0, 0],
            'int_positive' => [42, 42],
            'int_negative' => [-7, -7],
            'int_max' => [\PHP_INT_MAX, \PHP_INT_MAX],
            'int_min' => [\PHP_INT_MIN, \PHP_INT_MIN],

            // floats (truncate toward zero, matching zval_get_long)
            'float_zero' => [0.0, 0],
            'float_positive_low' => [1.5, 1],
            'float_positive_high' => [7.9, 7],
            'float_negative' => [-3.7, -3],
            'float_close_to_zero' => [0.999, 0],

            // bools
            'bool_true' => [true, 1],
            'bool_false' => [false, 0],

            // null
            'null' => [null, 0],

            // strings (leading-numeric coercion, zero for non-numeric)
            'string_int' => ['42', 42],
            'string_negative_int' => ['-7', -7],
            'string_leading_numeric' => ['3abc', 3],
            'string_float' => ['1.5', 1],
            'string_word' => ['not-an-int', 0],
            'string_empty' => ['', 0],
            'string_whitespace' => ['   ', 0],
            'string_signed_zero' => ['-0', 0],

            // arrays (non-empty → 1, empty → 0)
            'array_empty' => [[], 0],
            'array_sequential' => [[1, 2, 3], 1],
            'array_associative' => [['k' => 'v'], 1],

            // objects (always 1, like PECL's zval_get_long fallback)
            'object_plain' => [new \stdClass(), 1],
        ];
    }

    /**
     * Lock down PECL's {@code zval_get_long()} contract that
     * {@see ClientOptions::peclLongValue()} stands in for. Any drift here
     * propagates straight into options like {@code OPT_LIBKETAMA_HASH}
     * whose setter is defined in {@code php_memcached.c} as
     * {@code lval = zval_get_long(value)}.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('peclLongValueMatrix')]
    public function testPeclLongValueMatchesZvalGetLongSemantics(mixed $input, int $expected): void
    {
        self::assertSame($expected, ClientOptions::peclLongValue($input));
    }

    public function testPeclLongValueHandlesStringableObjectAsOne(): void
    {
        // PECL's zval_get_long never consults __toString for object → int
        // coercion (Zend separates the cast paths), so a Stringable object
        // still resolves to 1 — same as a plain object.
        $stringable = new class {
            public function __toString(): string
            {
                return '99';
            }
        };

        self::assertSame(1, ClientOptions::peclLongValue($stringable));
    }

    public function testPeclLongValueHandlesOpenResourceAsItsId(): void
    {
        $resource = fopen('php://memory', 'r');
        try {
            self::assertIsResource($resource);
            // Resources widen to their numeric id in zval_get_long — we
            // don't pin the exact id (it's runtime-allocated), just that
            // we forward the int cast PECL would.
            self::assertSame((int) $resource, ClientOptions::peclLongValue($resource));
        } finally {
            if (\is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    /**
     * @return array<string, array{CacheClient}>
     */
    public static function allBackendClients(): array
    {
        return [
            'memcached' => [new MemcachedClient()],
            'redis' => [new RedisClient()],
            'ignite' => [new IgniteClient()],
        ];
    }

    /**
     * OPT_LIBKETAMA_HASH parity lives entirely in shared infrastructure
     * ({@see \PureCache\Internal\ClientOptionApplier::applyLibketamaHash()}
     * for the setter and the separate {@code OPT_LIBKETAMA_HASH} slot in
     * {@see ClientOptions::defaults()}). On a fresh
     * client both dials start at {@code HASH_DEFAULT}; the cross-backend test
     * makes that invariant explicit before parity tests against ext-memcached.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('allBackendClients')]
    public function testLibketamaHashContractIsConsistentAcrossBackends(CacheClient $client): void
    {
        self::assertSame(
            MemcachedConstants::HASH_DEFAULT,
            $client->getOption(MemcachedConstants::OPT_LIBKETAMA_HASH),
            'fresh OPT_LIBKETAMA_HASH must match libmemcached ketama default',
        );
        self::assertSame(
            $client->getOption(MemcachedConstants::OPT_HASH),
            $client->getOption(MemcachedConstants::OPT_LIBKETAMA_HASH),
            'fresh OPT_LIBKETAMA_HASH must equal default OPT_HASH',
        );

        self::assertTrue($client->setOption(MemcachedConstants::OPT_LIBKETAMA_HASH, MemcachedConstants::HASH_MURMUR));
        self::assertSame(MemcachedConstants::RES_SUCCESS, $client->getResultCode());

        self::assertTrue($client->setOption(MemcachedConstants::OPT_LIBKETAMA_HASH, 'not-an-int'));
        self::assertSame(MemcachedConstants::RES_SUCCESS, $client->getResultCode());

        self::assertFalse($client->setOption(MemcachedConstants::OPT_LIBKETAMA_HASH, MemcachedConstants::HASH_HSIEH));
        self::assertSame(MemcachedConstants::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }
}
