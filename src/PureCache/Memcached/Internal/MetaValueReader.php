<?php

declare(strict_types=1);

namespace PureCache\Memcached\Internal;

use PureCache\Internal\EncodingContext;
use PureCache\Internal\ValueCodec;
use PureCache\MemcachedConstants;

/**
 * Converts raw meta get responses into decoded PHP values.
 *
 * @psalm-suppress MixedAssignment
 */
final class MetaValueReader
{
    private function __construct()
    {
    }

    public static function read(
        MetaReader $reader,
        int $serializer,
        bool $allowSerializedClasses = false,
        ?EncodingContext $encoding = null,
    ): DecodedMetaValue {
        $result = $reader->readOne(true);
        if (MetaReader::CODE_ITEM_TOO_BIG === $result->code) {
            return DecodedMetaValue::failure(MemcachedConstants::RES_E2BIG, $result->errorMessage);
        }

        $wire = $result->wireErrorResultCode();
        if (null !== $wire) {
            return DecodedMetaValue::failure($wire, $result->errorMessage);
        }

        if ('EN' === $result->code || 'NF' === $result->code) {
            return DecodedMetaValue::missing($result);
        }

        if ('VA' !== $result->code || null === $result->value) {
            return DecodedMetaValue::failure(MemcachedConstants::RES_FAILURE);
        }

        try {
            $value = ValueCodec::decode(
                $result->value,
                (int) ($result->getToken('f') ?? '0'),
                $serializer,
                $allowSerializedClasses,
                $encoding,
            );
        } catch (\Exception) {
            return DecodedMetaValue::failure(MemcachedConstants::RES_PAYLOAD_FAILURE);
        }

        return DecodedMetaValue::found($result, $value);
    }
}
