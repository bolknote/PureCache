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

    public function testOptionApplierRejectsNonStringPrefixValues(): void
    {
        $core = MemcachedClientCore::createFresh();

        $result = ClientOptionApplier::apply($core, MemcachedClient::OPT_PREFIX_KEY, ['not-a-string'], $this->fakeEnv());

        self::assertFalse($result->ok);
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $result->code);
    }

    public function testOptionApplierRejectsNonIntegerSelectorValues(): void
    {
        $core = MemcachedClientCore::createFresh();

        $result = ClientOptionApplier::apply($core, MemcachedClient::OPT_HASH, ['not-an-int'], $this->fakeEnv());

        self::assertFalse($result->ok);
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $result->code);
    }

    public function testOptionApplierRejectsInvalidPrefixKeys(): void
    {
        $core = MemcachedClientCore::createFresh();

        $result = ClientOptionApplier::apply($core, MemcachedClient::OPT_PREFIX_KEY, 'bad key', $this->fakeEnv());

        self::assertFalse($result->ok);
        self::assertSame(MemcachedClient::RES_BAD_KEY_PROVIDED, $result->code);
    }

    public function testEncodingModeOptionRequiresOpenSsl(): void
    {
        if (\extension_loaded('openssl')) {
            self::markTestSkipped('openssl is available');
        }

        $core = MemcachedClientCore::createFresh();
        $result = ClientOptionApplier::apply(
            $core,
            MemcachedClient::OPT_ENCODING_MODE,
            MemcachedClient::ENCODING_MODE_AEAD,
            $this->fakeEnv(),
        );

        self::assertFalse($result->ok);
        self::assertSame(MemcachedClient::RES_NOT_SUPPORTED, $result->code);
        self::assertSame('encoding modes require ext-openssl', $result->message);
    }

    private function fakeEnv(): OptionEnvironment
    {
        return new class implements OptionEnvironment {
            #[\Override]
            public function onPoolInvalidated(): void
            {
            }

            #[\Override]
            public function onTimeoutsChanged(): void
            {
            }

            #[\Override]
            public function isUnsupportedOption(int $option): bool
            {
                return false;
            }

            #[\Override]
            public function unsupportedOptionMessage(): string
            {
                return 'option is not supported by the pure PHP meta protocol client';
            }

            #[\Override]
            public function applyCustomOption(int $option, mixed $value, ClientCoreState $core): ?ClientOptionResult
            {
                return null;
            }

            #[\Override]
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

    public function testMetaReaderArithmeticPassesThroughHeaderResponse(): void
    {
        // ms/mr return HD on success; the arithmetic helper must surface that
        // verbatim instead of synthesising a VA-with-value, otherwise meta
        // increment/decrement on an absent key would look like a `0` reply.
        [$connection, $server] = $this->socketConnection("NS\r\n");
        $reader = new MetaReader($connection);
        $r = $reader->readArithmeticValue();

        self::assertSame('NS', $r->code);
        self::assertNull($r->value);
        self::assertNull($r->errorMessage);
        fclose($server);
    }

    public function testMetaReaderArithmeticSurfacesServerError(): void
    {
        [$connection, $server] = $this->socketConnection("SERVER_ERROR overloaded\r\n");
        $reader = new MetaReader($connection);
        $r = $reader->readArithmeticValue();

        self::assertSame('SERVER_ERROR', $r->code);
        self::assertSame('overloaded', $r->errorMessage);
        fclose($server);
    }

    public function testMetaReaderReadOneReturnsEmptyValueForZeroSizedVa(): void
    {
        // `VA 0` is what the meta protocol returns for an empty stored value
        // (e.g. an empty string written via `set`). The reader still has to
        // consume the trailing CRLF so the next response on this socket isn't
        // shifted by two bytes.
        [$connection, $server] = $this->socketConnection("VA 0 f0 c7\r\n\r\nHD\r\n");
        $reader = new MetaReader($connection);

        $first = $reader->readOne(true);
        $second = $reader->readOne(false);

        self::assertSame('VA', $first->code);
        self::assertSame('', $first->value);
        self::assertSame('7', $first->getCas());
        self::assertSame('HD', $second->code, 'CRLF after empty body must have been consumed');
        fclose($server);
    }

    public function testMetaReaderReadOneDiscardsValueBlockWhenNotRequested(): void
    {
        // Callers that pre-checked the response code can opt out of buffering
        // the value (e.g. probe queries). The reader still drains the body
        // from the wire so a subsequent readOne() lands on the next response.
        [$connection, $server] = $this->socketConnection("VA 5 f0 c1\r\nhello\r\nHD\r\n");
        $reader = new MetaReader($connection);

        $skipped = $reader->readOne(false);
        $next = $reader->readOne(false);

        self::assertSame('VA', $skipped->code);
        self::assertNull($skipped->value, 'expectValueBlock=false must drop the body');
        self::assertSame('HD', $next->code);
        fclose($server);
    }

    public function testMetaReaderReadOneSurfacesProtocolErrorOnEmptyLine(): void
    {
        // Servers should never send an empty line, but if a Dragonfly fork
        // does, we want a structured error rather than a hang on the next
        // chunk read.
        [$connection, $server] = $this->socketConnection("\r\n");
        $reader = new MetaReader($connection);

        $r = $reader->readOne(true);

        self::assertSame('', $r->code);
        self::assertSame('Empty response line', $r->errorMessage);
        self::assertSame(MemcachedClient::RES_PROTOCOL_ERROR, $r->wireErrorResultCode());
        fclose($server);
    }
}
