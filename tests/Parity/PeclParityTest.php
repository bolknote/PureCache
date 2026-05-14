<?php

declare(strict_types=1);

namespace PureCache\Tests\Parity;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PureCache\Memcached\MemcachedClient;

final class PeclParityTest extends TestCase
{
    private function host(): string
    {
        $host = getenv('MEMCACHED_TEST_HOST');

        return false !== $host ? $host : '127.0.0.1';
    }

    private function port(): int
    {
        $port = getenv('MEMCACHED_TEST_PORT');

        return false !== $port ? (int) $port : 11211;
    }

    public static function setUpBeforeClass(): void
    {
        if (!\extension_loaded('memcached')) {
            self::markTestSkipped('PECL memcached extension is not loaded');
        }
    }

    public function testEveryPeclConstantMatchesPureCache(): void
    {
        $pecl = (new \ReflectionClass(\Memcached::class))->getConstants();
        $pure = (new \ReflectionClass(MemcachedClient::class))->getConstants();

        // LIBMEMCACHED_VERSION_HEX is a compile-time stamp baked into
        // ext-memcached from whatever libmemcached the PECL package was built
        // against; the pure-PHP client has no library to track so it pins a
        // documented value and is intentionally not parity-checked.
        $skipValueChecks = ['LIBMEMCACHED_VERSION_HEX'];

        // HAVE_* are compile-time bool flags in PECL that reflect how the C
        // extension was built (whether libmemcached was linked with SASL, SSL,
        // protocol support, etc.). The pure-PHP build advertises support
        // independently from PECL's compile flags, so we check the *type* is
        // bool (PECL contract) but not the specific true/false value.
        $boolTypeOnly = [
            'HAVE_IGBINARY', 'HAVE_JSON', 'HAVE_MSGPACK', 'HAVE_ZSTD',
            'HAVE_ENCODING', 'HAVE_SESSION', 'HAVE_SASL',
        ];

        $missing = [];
        $mismatches = [];
        foreach ($pecl as $name => $value) {
            if (!\array_key_exists($name, $pure)) {
                $missing[] = $name;
                continue;
            }

            if (\in_array($name, $skipValueChecks, true)) {
                continue;
            }

            if (\in_array($name, $boolTypeOnly, true)) {
                if (!\is_bool($pure[$name])) {
                    $mismatches[] = \sprintf('%s: PECL is bool, PureCache=%s (%s)', $name, var_export($pure[$name], true), get_debug_type($pure[$name]));
                }

                continue;
            }

            if ($pure[$name] !== $value) {
                $mismatches[] = \sprintf('%s: PECL=%s, PureCache=%s', $name, var_export($value, true), var_export($pure[$name], true));
            }
        }

        self::assertSame([], $missing, 'PECL constants missing from PureCache\MemcachedConstants: '.implode(', ', $missing));
        self::assertSame([], $mismatches, "Constant value/type drift vs PECL Memcached:\n".implode("\n", $mismatches));
    }

    /**
     * PECL picks {@code OPT_SERIALIZER}'s default from compile-time
     * {@code HAVE_IGBINARY}/{@code HAVE_MSGPACK} flags ("igbinary if available,
     * then msgpack, then PHP"). PureCache has no compile step, so it derives
     * the same precedence from {@see \extension_loaded()}. When PECL's build
     * flags line up with the PHP extensions actually loaded — the typical
     * case for distro-packaged builds — both clients must return the same
     * default. Mismatches between PECL's flags and the runtime extensions
     * (e.g. PECL built without igbinary support but ext-igbinary still loaded
     * for PureCache to use) are unavoidable and explicitly skipped.
     */
    public function testDefaultSerializerMatchesPeclPrecedence(): void
    {
        $peclIgbinaryAdvertised = \Memcached::HAVE_IGBINARY;
        $peclMsgpackAdvertised = \Memcached::HAVE_MSGPACK;

        $igbinaryLoaded = \extension_loaded('igbinary');
        $msgpackLoaded = \extension_loaded('msgpack');

        if ($peclIgbinaryAdvertised !== $igbinaryLoaded || $peclMsgpackAdvertised !== $msgpackLoaded) {
            self::markTestSkipped('PECL HAVE_* compile flags disagree with the PHP extensions loaded in this runtime; OPT_SERIALIZER defaults are not directly comparable.');
        }

        $peclDefault = (new \Memcached())->getOption(\Memcached::OPT_SERIALIZER);
        $pureDefault = (new MemcachedClient())->getOption(MemcachedClient::OPT_SERIALIZER);

        self::assertSame($peclDefault, $pureDefault);
    }

    public function testSupportedOptionSetGetParity(): void
    {
        $this->assertParity(
            static fn (\Memcached $client, string $prefix): array => [
                'compressionDefault' => $client->getOption(\Memcached::OPT_COMPRESSION),
                'tcpNoDelayDefault' => $client->getOption(\Memcached::OPT_TCP_NODELAY),
                'tcpNoDelaySet' => $client->setOption(\Memcached::OPT_TCP_NODELAY, true),
                'tcpNoDelayAfter' => $client->getOption(\Memcached::OPT_TCP_NODELAY),
                'noBlockDefault' => $client->getOption(\Memcached::OPT_NO_BLOCK),
                'noBlockSet' => $client->setOption(\Memcached::OPT_NO_BLOCK, true),
                'noBlockAfter' => $client->getOption(\Memcached::OPT_NO_BLOCK),
                'keepaliveDefault' => $client->getOption(\Memcached::OPT_TCP_KEEPALIVE),
                'keepaliveSet' => $client->setOption(\Memcached::OPT_TCP_KEEPALIVE, true),
                'keepaliveAfter' => $client->getOption(\Memcached::OPT_TCP_KEEPALIVE),
                'sendSizeSet' => $client->setOption(\Memcached::OPT_SOCKET_SEND_SIZE, 8192),
                'sendSizeAfter' => $client->getOption(\Memcached::OPT_SOCKET_SEND_SIZE),
                'recvSizeSet' => $client->setOption(\Memcached::OPT_SOCKET_RECV_SIZE, 8192),
                'recvSizeAfter' => $client->getOption(\Memcached::OPT_SOCKET_RECV_SIZE),
            ],
            static fn (MemcachedClient $client, string $prefix): array => [
                'compressionDefault' => $client->getOption(MemcachedClient::OPT_COMPRESSION),
                'tcpNoDelayDefault' => $client->getOption(MemcachedClient::OPT_TCP_NODELAY),
                'tcpNoDelaySet' => $client->setOption(MemcachedClient::OPT_TCP_NODELAY, true),
                'tcpNoDelayAfter' => $client->getOption(MemcachedClient::OPT_TCP_NODELAY),
                'noBlockDefault' => $client->getOption(MemcachedClient::OPT_NO_BLOCK),
                'noBlockSet' => $client->setOption(MemcachedClient::OPT_NO_BLOCK, true),
                'noBlockAfter' => $client->getOption(MemcachedClient::OPT_NO_BLOCK),
                'keepaliveDefault' => $client->getOption(MemcachedClient::OPT_TCP_KEEPALIVE),
                'keepaliveSet' => $client->setOption(MemcachedClient::OPT_TCP_KEEPALIVE, true),
                'keepaliveAfter' => $client->getOption(MemcachedClient::OPT_TCP_KEEPALIVE),
                'sendSizeSet' => $client->setOption(MemcachedClient::OPT_SOCKET_SEND_SIZE, 8192),
                'sendSizeAfter' => $client->getOption(MemcachedClient::OPT_SOCKET_SEND_SIZE),
                'recvSizeSet' => $client->setOption(MemcachedClient::OPT_SOCKET_RECV_SIZE, 8192),
                'recvSizeAfter' => $client->getOption(MemcachedClient::OPT_SOCKET_RECV_SIZE),
            ],
        );
    }

    public function testPeclStyleSetOptionsParity(): void
    {
        $this->assertParity(
            static fn (\Memcached $client, string $prefix): array => [
                'setOptions' => $client->setOptions([
                    \Memcached::OPT_PREFIX_KEY => $prefix,
                    \Memcached::OPT_NO_BLOCK => true,
                    \Memcached::OPT_RECV_TIMEOUT => 3000,
                    \Memcached::OPT_SEND_TIMEOUT => 1000,
                    \Memcached::OPT_TCP_NODELAY => true,
                    \Memcached::OPT_COMPRESSION => true,
                    \Memcached::OPT_SERIALIZER => \Memcached::SERIALIZER_IGBINARY,
                    \Memcached::OPT_LIBKETAMA_COMPATIBLE => true,
                ]),
                'prefix' => $client->getOption(\Memcached::OPT_PREFIX_KEY),
                'noBlock' => $client->getOption(\Memcached::OPT_NO_BLOCK),
                'recvTimeout' => $client->getOption(\Memcached::OPT_RECV_TIMEOUT),
                'sendTimeout' => $client->getOption(\Memcached::OPT_SEND_TIMEOUT),
                'tcpNoDelay' => $client->getOption(\Memcached::OPT_TCP_NODELAY),
                'compression' => $client->getOption(\Memcached::OPT_COMPRESSION),
                'serializer' => $client->getOption(\Memcached::OPT_SERIALIZER),
                'libketama' => $client->getOption(\Memcached::OPT_LIBKETAMA_COMPATIBLE),
            ],
            static fn (MemcachedClient $client, string $prefix): array => [
                'setOptions' => $client->setOptions([
                    MemcachedClient::OPT_PREFIX_KEY => $prefix,
                    MemcachedClient::OPT_NO_BLOCK => true,
                    MemcachedClient::OPT_RECV_TIMEOUT => 3000,
                    MemcachedClient::OPT_SEND_TIMEOUT => 1000,
                    MemcachedClient::OPT_TCP_NODELAY => true,
                    MemcachedClient::OPT_COMPRESSION => true,
                    MemcachedClient::OPT_SERIALIZER => MemcachedClient::SERIALIZER_IGBINARY,
                    MemcachedClient::OPT_LIBKETAMA_COMPATIBLE => true,
                ]),
                'prefix' => $client->getOption(MemcachedClient::OPT_PREFIX_KEY),
                'noBlock' => $client->getOption(MemcachedClient::OPT_NO_BLOCK),
                'recvTimeout' => $client->getOption(MemcachedClient::OPT_RECV_TIMEOUT),
                'sendTimeout' => $client->getOption(MemcachedClient::OPT_SEND_TIMEOUT),
                'tcpNoDelay' => $client->getOption(MemcachedClient::OPT_TCP_NODELAY),
                'compression' => $client->getOption(MemcachedClient::OPT_COMPRESSION),
                'serializer' => $client->getOption(MemcachedClient::OPT_SERIALIZER),
                'libketama' => $client->getOption(MemcachedClient::OPT_LIBKETAMA_COMPATIBLE),
            ],
        );
    }

    public function testIgbinarySerializerRoundTripParity(): void
    {
        $this->assertParity(
            static function (\Memcached $client, string $prefix): array {
                $key = $prefix.'igbinary';
                $client->setOption(\Memcached::OPT_SERIALIZER, \Memcached::SERIALIZER_IGBINARY);
                $value = [
                    'list' => [1, 2, 3],
                    'assoc' => ['enabled' => true, 'name' => 'igbinary'],
                    'nested' => [['a' => 1], ['b' => null]],
                ];

                return [
                    'serializer' => $client->getOption(\Memcached::OPT_SERIALIZER),
                    'set' => $client->set($key, $value, 60),
                    'setCode' => $client->getResultCode(),
                    'get' => $client->get($key),
                    'getCode' => $client->getResultCode(),
                ];
            },
            static function (MemcachedClient $client, string $prefix): array {
                $key = $prefix.'igbinary';
                $client->setOption(MemcachedClient::OPT_SERIALIZER, MemcachedClient::SERIALIZER_IGBINARY);
                $value = [
                    'list' => [1, 2, 3],
                    'assoc' => ['enabled' => true, 'name' => 'igbinary'],
                    'nested' => [['a' => 1], ['b' => null]],
                ];

                return [
                    'serializer' => $client->getOption(MemcachedClient::OPT_SERIALIZER),
                    'set' => $client->set($key, $value, 60),
                    'setCode' => $client->getResultCode(),
                    'get' => $client->get($key),
                    'getCode' => $client->getResultCode(),
                ];
            },
        );
    }

    public function testBasicMutationAndRetrievalParity(): void
    {
        $this->assertParity(
            static function (\Memcached $client, string $prefix): array {
                $key = $prefix.'basic';

                return [
                    'set' => $client->set($key, ['nested' => ['ok' => true]], 60),
                    'setCode' => $client->getResultCode(),
                    'get' => $client->get($key),
                    'getCode' => $client->getResultCode(),
                    'delete' => $client->delete($key),
                    'deleteCode' => $client->getResultCode(),
                    'missing' => $client->get($key),
                    'missingCode' => $client->getResultCode(),
                ];
            },
            static function (MemcachedClient $client, string $prefix): array {
                $key = $prefix.'basic';

                return [
                    'set' => $client->set($key, ['nested' => ['ok' => true]], 60),
                    'setCode' => $client->getResultCode(),
                    'get' => $client->get($key),
                    'getCode' => $client->getResultCode(),
                    'delete' => $client->delete($key),
                    'deleteCode' => $client->getResultCode(),
                    'missing' => $client->get($key),
                    'missingCode' => $client->getResultCode(),
                ];
            },
        );
    }

    public function testExtendedGetAndMultiParity(): void
    {
        $this->assertParity(
            static function (\Memcached $client, string $prefix): array {
                $keys = [$prefix.'ext-a', $prefix.'ext-b'];
                $client->setMulti([$keys[0] => 'a', $keys[1] => 'b'], 60);

                return [
                    'single' => $client->get($keys[0], null, \Memcached::GET_EXTENDED),
                    'singleCode' => $client->getResultCode(),
                    'multi' => $client->getMulti($keys, \Memcached::GET_EXTENDED | \Memcached::GET_PRESERVE_ORDER),
                    'multiCode' => $client->getResultCode(),
                ];
            },
            static function (MemcachedClient $client, string $prefix): array {
                $keys = [$prefix.'ext-a', $prefix.'ext-b'];
                $client->setMulti([$keys[0] => 'a', $keys[1] => 'b'], 60);

                return [
                    'single' => $client->get($keys[0], null, MemcachedClient::GET_EXTENDED),
                    'singleCode' => $client->getResultCode(),
                    'multi' => $client->getMulti($keys, MemcachedClient::GET_EXTENDED | MemcachedClient::GET_PRESERVE_ORDER),
                    'multiCode' => $client->getResultCode(),
                ];
            },
        );
    }

    public function testStorageMutationParity(): void
    {
        $this->assertParity(
            static function (\Memcached $client, string $prefix): array {
                $key = $prefix.'mutation';

                return [
                    'add' => $client->add($key, 'b', 60),
                    'addAgain' => $client->add($key, 'ignored', 60),
                    'addAgainCode' => $client->getResultCode(),
                    'replace' => $client->replace($key, 'b', 60),
                    'prepend' => $client->prepend($key, 'a'),
                    'append' => $client->append($key, 'c'),
                    'value' => $client->get($key),
                    'touch' => $client->touch($key, 60),
                ];
            },
            static function (MemcachedClient $client, string $prefix): array {
                $key = $prefix.'mutation';

                return [
                    'add' => $client->add($key, 'b', 60),
                    'addAgain' => $client->add($key, 'ignored', 60),
                    'addAgainCode' => $client->getResultCode(),
                    'replace' => $client->replace($key, 'b', 60),
                    'prepend' => $client->prepend($key, 'a'),
                    'append' => $client->append($key, 'c'),
                    'value' => $client->get($key),
                    'touch' => $client->touch($key, 60),
                ];
            },
        );
    }

    public function testArithmeticParity(): void
    {
        $this->assertParity(
            static function (\Memcached $client, string $prefix): array {
                $key = $prefix.'counter';

                return [
                    'missingIncrement' => $client->increment($key, 2),
                    'missingCode' => $client->getResultCode(),
                    'initial' => $client->increment($key, 1, 0, 60),
                    'increment' => $client->increment($key, 2),
                    'decrement' => $client->decrement($key, 1),
                ];
            },
            static function (MemcachedClient $client, string $prefix): array {
                $key = $prefix.'counter';

                return [
                    'missingIncrement' => $client->increment($key, 2),
                    'missingCode' => $client->getResultCode(),
                    'initial' => $client->increment($key, 1, 0, 60),
                    'increment' => $client->increment($key, 2),
                    'decrement' => $client->decrement($key, 1),
                ];
            },
            peclBinary: true,
        );
    }

    /**
     * PECL passes the user's expiration argument through to libmemcached
     * untouched, and the memcached server itself implements the "{@code > 30
     * days = absolute Unix timestamp}" cut-off. Our implementation must
     * preserve that contract on both backends — see
     * {@see \PureCache\Internal\Expiration::toRelativeSeconds()}.
     */
    public function testArithmeticAutoCreateWithAbsoluteExpirationParity(): void
    {
        $this->assertParity(
            static function (\Memcached $client, string $prefix): array {
                $key = $prefix.'abs-ttl';
                $absolute = time() + 60;

                return [
                    'initial' => $client->increment($key, 1, 5, $absolute),
                    'initialCode' => $client->getResultCode(),
                    'value' => $client->get($key),
                ];
            },
            static function (MemcachedClient $client, string $prefix): array {
                $key = $prefix.'abs-ttl';
                $absolute = time() + 60;

                return [
                    'initial' => $client->increment($key, 1, 5, $absolute),
                    'initialCode' => $client->getResultCode(),
                    'value' => $client->get($key),
                ];
            },
            peclBinary: true,
        );
    }

    public function testDelayedFetchShapeParity(): void
    {
        $this->assertParity(
            static function (\Memcached $client, string $prefix): array {
                $keys = [$prefix.'delay-a', $prefix.'delay-b'];
                $client->setMulti([$keys[0] => 'a', $keys[1] => 'b'], 60);
                $queuedWithCas = $client->getDelayed($keys, true);
                $withCas = $client->fetchAll();
                $client->getDelayed($keys, false);
                $withoutCas = $client->fetchAll();

                return [
                    'queuedWithCas' => $queuedWithCas,
                    'withCas' => $withCas,
                    'withoutCas' => $withoutCas,
                ];
            },
            static function (MemcachedClient $client, string $prefix): array {
                $keys = [$prefix.'delay-a', $prefix.'delay-b'];
                $client->setMulti([$keys[0] => 'a', $keys[1] => 'b'], 60);
                $queuedWithCas = $client->getDelayed($keys, true);
                $withCas = $client->fetchAll();
                $client->getDelayed($keys, false);
                $withoutCas = $client->fetchAll();

                return [
                    'queuedWithCas' => $queuedWithCas,
                    'withCas' => $withCas,
                    'withoutCas' => $withoutCas,
                ];
            },
        );
    }

    /**
     * Pin OPT_LIBKETAMA_HASH to PECL's exact contract. The dial is a
     * read-alias of OPT_HASH (libmemcached writes through to the same
     * hashkit field that backs MEMCACHED_BEHAVIOR_HASH) and the setter is
     * routed through {@code zval_get_long()} → libmemcached → hashkit; the
     * only value that fails is HASH_HSIEH because PECL builds without
     * HAVE_HSIEH_HASH. The probe matrix mirrors a runtime audit against a
     * live ext-memcached: drop any case here and parity bugs slip in
     * silently for callers porting from the C extension.
     *
     * @return iterable<string, array{mixed, int}>
     */
    public static function libketamaHashCoercionMatrix(): iterable
    {
        yield 'int_default' => [\Memcached::HASH_DEFAULT, \Memcached::RES_SUCCESS];
        yield 'int_md5' => [\Memcached::HASH_MD5, \Memcached::RES_SUCCESS];
        yield 'int_crc' => [\Memcached::HASH_CRC, \Memcached::RES_SUCCESS];
        yield 'int_murmur' => [\Memcached::HASH_MURMUR, \Memcached::RES_SUCCESS];
        yield 'int_hsieh' => [\Memcached::HASH_HSIEH, \Memcached::RES_INVALID_ARGUMENTS];
        yield 'int_bogus' => [9999, \Memcached::RES_SUCCESS];
        yield 'int_negative' => [-3, \Memcached::RES_SUCCESS];
        yield 'string_numeric' => ['5', \Memcached::RES_SUCCESS];
        yield 'string_hsieh_numeric' => ['7', \Memcached::RES_INVALID_ARGUMENTS];
        yield 'string_mixed' => ['3abc', \Memcached::RES_SUCCESS];
        yield 'string_empty' => ['', \Memcached::RES_SUCCESS];
        yield 'string_word' => ['not-an-int', \Memcached::RES_SUCCESS];
        yield 'null' => [null, \Memcached::RES_SUCCESS];
        yield 'bool_true' => [true, \Memcached::RES_SUCCESS];
        yield 'bool_false' => [false, \Memcached::RES_SUCCESS];
        yield 'float_safe' => [1.5, \Memcached::RES_SUCCESS];
        yield 'float_truncates_to_hsieh' => [7.9, \Memcached::RES_INVALID_ARGUMENTS];
    }

    #[DataProvider('libketamaHashCoercionMatrix')]
    public function testLibketamaHashCoercionMatchesPecl(mixed $input, int $expectedRc): void
    {
        $pecl = new \Memcached();
        $pure = new MemcachedClient();

        $peclOk = @$pecl->setOption(\Memcached::OPT_LIBKETAMA_HASH, $input);
        $pureOk = $pure->setOption(MemcachedClient::OPT_LIBKETAMA_HASH, $input);

        self::assertSame($peclOk, $pureOk, 'setOption boolean return drifts from PECL');
        self::assertSame($expectedRc, $pecl->getResultCode(), 'parity matrix entry is stale vs PECL');
        self::assertSame($pecl->getResultCode(), $pure->getResultCode(), 'getResultCode drifts from PECL');
    }

    public function testLibketamaHashGetterTracksOptHashAcrossCascade(): void
    {
        $pecl = new \Memcached();
        $pure = new MemcachedClient();

        self::assertSame(
            $pecl->getOption(\Memcached::OPT_LIBKETAMA_HASH),
            $pure->getOption(MemcachedClient::OPT_LIBKETAMA_HASH),
            'fresh-client OPT_LIBKETAMA_HASH must equal default OPT_HASH',
        );

        foreach ([\Memcached::HASH_CRC, \Memcached::HASH_MURMUR, \Memcached::HASH_FNV1_64] as $hash) {
            $pecl->setOption(\Memcached::OPT_HASH, $hash);
            $pure->setOption(MemcachedClient::OPT_HASH, $hash);
            self::assertSame(
                $pecl->getOption(\Memcached::OPT_LIBKETAMA_HASH),
                $pure->getOption(MemcachedClient::OPT_LIBKETAMA_HASH),
                'OPT_LIBKETAMA_HASH must read-alias OPT_HASH for hash='.$hash,
            );
        }

        // LIBKETAMA_COMPATIBLE=true cascades OPT_HASH→MD5; the alias must follow.
        $pecl->setOption(\Memcached::OPT_LIBKETAMA_COMPATIBLE, true);
        $pure->setOption(MemcachedClient::OPT_LIBKETAMA_COMPATIBLE, true);
        self::assertSame(
            $pecl->getOption(\Memcached::OPT_LIBKETAMA_HASH),
            $pure->getOption(MemcachedClient::OPT_LIBKETAMA_HASH),
            'OPT_LIBKETAMA_HASH after LIBKETAMA_COMPATIBLE=true must mirror PECL',
        );

        // Setter is a no-op for routing: the getter must keep tracking OPT_HASH,
        // not the value just passed in.
        $pecl->setOption(\Memcached::OPT_LIBKETAMA_HASH, \Memcached::HASH_MURMUR);
        $pure->setOption(MemcachedClient::OPT_LIBKETAMA_HASH, MemcachedClient::HASH_MURMUR);
        self::assertSame(
            $pecl->getOption(\Memcached::OPT_LIBKETAMA_HASH),
            $pure->getOption(MemcachedClient::OPT_LIBKETAMA_HASH),
            'setOption(OPT_LIBKETAMA_HASH) must not move the getter off OPT_HASH',
        );
        self::assertSame(
            $pecl->getOption(\Memcached::OPT_HASH),
            $pure->getOption(MemcachedClient::OPT_HASH),
            'setOption(OPT_LIBKETAMA_HASH) must not move OPT_HASH either',
        );
    }

    public function testServerWideResponseShapeParity(): void
    {
        $this->assertParity(
            static function (\Memcached $client, string $prefix): array {
                $client->set($prefix.'stats', 'value', 60);

                return [
                    'version' => self::normalizeVersionMap($client->getVersion()),
                    'versionCode' => $client->getResultCode(),
                    'stats' => self::normalizeStatsMap($client->getStats()),
                    'statsCode' => $client->getResultCode(),
                    'items' => self::normalizeStatsMap($client->getStats('items')),
                    'itemsCode' => $client->getResultCode(),
                ];
            },
            static function (MemcachedClient $client, string $prefix): array {
                $client->set($prefix.'stats', 'value', 60);

                return [
                    'version' => self::normalizeVersionMap($client->getVersion()),
                    'versionCode' => $client->getResultCode(),
                    'stats' => self::normalizeStatsMap($client->getStats()),
                    'statsCode' => $client->getResultCode(),
                    'items' => self::normalizeStatsMap($client->getStats('items')),
                    'itemsCode' => $client->getResultCode(),
                ];
            },
        );
    }

    /**
     * @param callable(\Memcached, string): array<string, mixed>      $peclScenario
     * @param callable(MemcachedClient, string): array<string, mixed> $pureScenario
     */
    private function assertParity(callable $peclScenario, callable $pureScenario, bool $peclBinary = false): void
    {
        $prefix = 'parity_'.bin2hex(random_bytes(6)).'_';

        $pecl = $this->peclClient($peclBinary);
        $pecl->flush();

        $peclResult = $this->normalizeForParity($peclScenario($pecl, $prefix));

        $pure = $this->pureClient();
        $pure->flush();

        $pureResult = $this->normalizeForParity($pureScenario($pure, $prefix));

        self::assertSame($peclResult, $pureResult);
    }

    private function peclClient(bool $binary = false): \Memcached
    {
        $client = new \Memcached();
        $client->addServer($this->host(), $this->port());
        $client->setOption(\Memcached::OPT_COMPRESSION, false);
        $client->setOption(\Memcached::OPT_SERIALIZER, \Memcached::SERIALIZER_PHP);
        if ($binary) {
            // PureCache speaks the meta protocol, which is semantically
            // equivalent to PECL's binary mode for features like
            // increment_with_initial. Match that contract only on the cases
            // that exercise it.
            $client->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
        }

        return $client;
    }

    private function pureClient(): MemcachedClient
    {
        $client = new MemcachedClient();
        $client->addServer($this->host(), $this->port());
        $client->setOption(MemcachedClient::OPT_COMPRESSION, false);
        $client->setOption(MemcachedClient::OPT_SERIALIZER, MemcachedClient::SERIALIZER_PHP);

        return $client;
    }

    private function normalizeForParity(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if ('cas' === $key) {
                $normalized[$key] = $this->normalizeCas($item);
                continue;
            }

            if (\is_array($item)) {
                $normalized[$key] = $this->normalizeForParity($item);
                continue;
            }

            $normalized[$key] = $item;
        }

        return $normalized;
    }

    /**
     * @return array{type:string,zero:bool,present:bool}
     */
    private function normalizeCas(mixed $cas): array
    {
        return [
            'type' => get_debug_type($cas),
            'zero' => 0 === $cas || '0' === $cas,
            'present' => null !== $cas,
        ];
    }

    /**
     * @param array<array-key, mixed>|false $versions
     *
     * @return array<array-key, string>|false
     */
    private static function normalizeVersionMap(array|false $versions): array|false
    {
        if (false === $versions) {
            return false;
        }

        return array_map(static fn (mixed $value): string => \is_string($value) && '' !== $value ? '<version>' : '', $versions);
    }

    /**
     * @param array<array-key, mixed>|false $stats
     *
     * @return array<string, array<string, string>|false>|false
     */
    private static function normalizeStatsMap(array|false $stats): array|false
    {
        if (false === $stats) {
            return false;
        }

        $normalized = [];
        foreach ($stats as $server => $serverStats) {
            $serverKey = (string) $server;
            if (false === $serverStats) {
                $normalized[$serverKey] = false;
                continue;
            }

            self::assertIsArray($serverStats);
            $normalizedServerStats = [];
            foreach ($serverStats as $statKey => $statValue) {
                $normalizedServerStats[(string) $statKey] = \is_string($statValue) ? '<string>' : get_debug_type($statValue);
            }

            $normalized[$serverKey] = $normalizedServerStats;
            ksort($normalized[$serverKey]);
        }

        ksort($normalized);

        return $normalized;
    }
}
