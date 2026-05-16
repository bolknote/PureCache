<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache\Internal;

use PHPUnit\Framework\TestCase;
use PureCache\Internal\ClientCoordinatorEnv;
use PureCache\Internal\ClientStoreEncoder;
use PureCache\Internal\StoreMode;
use PureCache\Memcached\Internal\MemcachedClientCore;
use PureCache\Memcached\MemcachedClient;
use PureCache\MemcachedConstants;

final class ClientStoreEncoderTest extends TestCase
{
    public function testEncodeReturnsPayloadAndFlags(): void
    {
        $encoder = $this->encoder();
        $encoded = $encoder->encode(['ok' => true]);
        self::assertNotNull($encoded);
        [$payload, $flags] = $encoded;
        self::assertNotSame('', $payload);
        self::assertGreaterThanOrEqual(0, $flags);
    }

    public function testRejectIncompatibleConcatenationWhenCompressionEnabled(): void
    {
        $core = MemcachedClientCore::createFresh();
        $core->options[MemcachedClient::OPT_COMPRESSION] = true;
        $encoder = new ClientStoreEncoder(
            $this->env($core),
            static fn (): null => null,
        );

        self::assertFalse(@$encoder->rejectIncompatibleConcatenation(StoreMode::Append));
    }

    public function testEncodeMapsValueCodecFailuresToPayloadFailure(): void
    {
        $core = MemcachedClientCore::createFresh();
        $core->options[MemcachedClient::OPT_SERIALIZER] = MemcachedClient::SERIALIZER_JSON;
        $encoder = new ClientStoreEncoder($this->env($core), static fn (): null => null);

        $bad = new class implements \JsonSerializable {
            #[\Override]
            public function jsonSerialize(): mixed
            {
                throw new \RuntimeException('encode boom');
            }
        };

        self::assertNull($encoder->encode($bad));
        self::assertSame(MemcachedConstants::RES_PAYLOAD_FAILURE, $core->resultCode);
    }

    public function testEncodeRejectsOversizedPayload(): void
    {
        $core = MemcachedClientCore::createFresh();
        $core->options[MemcachedClient::OPT_ITEM_SIZE_LIMIT] = 4;
        $encoder = new ClientStoreEncoder($this->env($core), static fn (): null => null);

        self::assertNull($encoder->encode(str_repeat('x', 32)));
        self::assertSame(MemcachedConstants::RES_E2BIG, $core->resultCode);
    }

    private function encoder(): ClientStoreEncoder
    {
        $core = MemcachedClientCore::createFresh();

        return new ClientStoreEncoder(
            $this->env($core),
            static fn (): null => null,
        );
    }

    private function env(MemcachedClientCore $core): ClientCoordinatorEnv
    {
        return new ClientCoordinatorEnv(
            $core,
            static function (int $code, ?string $message = null) use ($core): void {
                $core->resultCode = $code;
                $core->resultMessage = $message ?? '';
            },
            static fn (): int => $core->resultCode,
            static fn (int $option, int $default): int => $core->optionInt($option, $default),
            static fn (int $option, bool $default): bool => $core->optionBool($option, $default),
            static fn (string $key): string => $key,
            static fn (string $key): string => $key,
            static fn (string $_key): bool => true,
        );
    }
}
