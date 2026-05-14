<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCoreState;
use PureCache\Internal\ClientOptionApplier;
use PureCache\Internal\ClientOptionResult;
use PureCache\Internal\KeyFormatter;
use PureCache\Internal\OptionEnvironment;
use PureCache\Internal\ValueCodec;
use PureCache\Memcached\Internal\MemcachedClientCore;
use PureCache\Memcached\Internal\MetaReader;
use PureCache\Memcached\Internal\MetaValueReader;
use PureCache\Memcached\Internal\StreamConnection;
use PureCache\Memcached\MemcachedClient;

final class InternalComponentsTest extends TestCase
{
    /**
     * @return array{0: StreamConnection, 1: resource}
     */
    private function socketConnection(string $serverData): array
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        [$client, $server] = $pair;
        fwrite($server, $serverData);

        $connection = new StreamConnection('127.0.0.1', 11211, 0.1, null, null);
        $socket = new \ReflectionProperty(StreamConnection::class, 'socket');
        $socket->setValue($connection, $client);

        return [$connection, $server];
    }

    public function testKeyFormatterHandlesPrefixRoutingAndMetaEncoding(): void
    {
        $options = [
            MemcachedClient::OPT_PREFIX_KEY => 'prefix:',
            MemcachedClient::OPT_HASH_WITH_PREFIX_KEY => true,
        ];

        self::assertSame('prefix:item', KeyFormatter::prefixed('item', $options));
        self::assertSame('prefix:item', KeyFormatter::routing('item', $options));
        self::assertTrue(KeyFormatter::isValid('valid:key'));
        self::assertFalse(KeyFormatter::isValid('bad key'));
        self::assertSame(['plain', ''], KeyFormatter::encodeMetaKey('plain'));
        self::assertSame([base64_encode("binary\0key"), ' b'], KeyFormatter::encodeMetaKey("binary\0key"));
    }

    public function testOptionApplierRejectsUnsupportedOptionsWithoutMutatingState(): void
    {
        $core = MemcachedClientCore::createFresh();

        $result = ClientOptionApplier::apply($core, MemcachedClient::OPT_BINARY_PROTOCOL, true, $this->fakeEnv());

        self::assertFalse($result->ok);
        self::assertSame(28, $result->code);
        self::assertSame('option is not supported by the pure PHP meta protocol client', $result->message);
        self::assertFalse($core->options[MemcachedClient::OPT_BINARY_PROTOCOL]);
    }

    public function testOptionApplierUpdatesDependentLibketamaOptions(): void
    {
        $core = MemcachedClientCore::createFresh();

        $result = ClientOptionApplier::apply($core, MemcachedClient::OPT_LIBKETAMA_COMPATIBLE, true, $this->fakeEnv());

        self::assertTrue($result->ok);
        self::assertTrue($core->options[MemcachedClient::OPT_LIBKETAMA_COMPATIBLE]);
        self::assertSame(MemcachedClient::DISTRIBUTION_CONSISTENT, $core->options[MemcachedClient::OPT_DISTRIBUTION]);
        self::assertSame(MemcachedClient::HASH_MD5, $core->options[MemcachedClient::OPT_HASH]);
    }

    private function fakeEnv(): OptionEnvironment
    {
        return new class implements OptionEnvironment {
            public function onPoolInvalidated(): void
            {
            }

            public function onTimeoutsChanged(): void
            {
            }

            public function isUnsupportedOption(int $option): bool
            {
                return false;
            }

            public function unsupportedOptionMessage(): string
            {
                return 'option is not supported by the pure PHP meta protocol client';
            }

            public function applyCustomOption(int $option, mixed $value, ClientCoreState $core): ?ClientOptionResult
            {
                return null;
            }

            public function maxKeyLength(): int
            {
                return 250;
            }
        };
    }

    public function testMetaValueReaderDecodesValuesAndMisses(): void
    {
        [$payload, $flags] = ValueCodec::encode(
            ['decoded' => true],
            MemcachedClient::SERIALIZER_PHP,
            false,
            MemcachedClient::COMPRESSION_ZLIB,
            3,
            2000,
            1.30,
            -1,
        );
        [$connection, $server] = $this->socketConnection('VA '.\strlen($payload).' f'.$flags." c42\r\n".$payload."\r\nEN\r\n");

        $reader = new MetaReader($connection);
        $found = MetaValueReader::read($reader, MemcachedClient::SERIALIZER_PHP);
        $missing = MetaValueReader::read($reader, MemcachedClient::SERIALIZER_PHP);

        self::assertTrue($found->found);
        self::assertSame(['decoded' => true], $found->value);
        self::assertSame('42', $found->result()->getCas());
        self::assertFalse($missing->found);
        self::assertFalse($missing->isFailure());
        fclose($server);
    }

    public function testMetaValueReaderReturnsWireAndPayloadFailures(): void
    {
        [$protocolConnection, $protocolServer] = $this->socketConnection("CLIENT_ERROR bad meta\r\n");
        $protocol = MetaValueReader::read(new MetaReader($protocolConnection), MemcachedClient::SERIALIZER_PHP);
        self::assertTrue($protocol->isFailure());
        self::assertSame(MemcachedClient::RES_CLIENT_ERROR, $protocol->errorCode);
        self::assertSame('bad meta', $protocol->errorMessage);
        fclose($protocolServer);

        [$payloadConnection, $payloadServer] = $this->socketConnection("VA 10 f999999\r\nnot-valid!\r\n");
        $payload = MetaValueReader::read(new MetaReader($payloadConnection), MemcachedClient::SERIALIZER_PHP);
        self::assertTrue($payload->isFailure());
        self::assertSame(MemcachedClient::RES_PAYLOAD_FAILURE, $payload->errorCode);
        fclose($payloadServer);
    }

    public function testMetaValueReaderReportsGenericFailureForUnknownResponseCode(): void
    {
        // HD/OK-style header replies are valid for ms/md but never for mg's
        // value path. The reader has to translate them into RES_FAILURE so
        // higher-level callers don't read a stale value out of the cache.
        [$connection, $server] = $this->socketConnection("HD\r\n");

        $result = MetaValueReader::read(new MetaReader($connection), MemcachedClient::SERIALIZER_PHP);

        self::assertTrue($result->isFailure());
        self::assertSame(MemcachedClient::RES_FAILURE, $result->errorCode);
        fclose($server);
    }

    public function testMetaReaderArithmeticAcceptsBareDecimalLine(): void
    {
        [$connection, $server] = $this->socketConnection("12\r\n");
        $reader = new MetaReader($connection);
        $r = $reader->readArithmeticValue();

        self::assertSame('VA', $r->code);
        self::assertSame('12', $r->value);
        self::assertNull($r->errorMessage);
        fclose($server);
    }

    public function testMetaReaderArithmeticStillParsesStandardVa(): void
    {
        [$connection, $server] = $this->socketConnection("VA 2 f0 c1 t60\r\n14\r\n");
        $reader = new MetaReader($connection);
        $r = $reader->readArithmeticValue();

        self::assertSame('VA', $r->code);
        self::assertSame('14', $r->value);
        self::assertSame('1', $r->getToken('c'));
        fclose($server);
    }
}
