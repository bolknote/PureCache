<?php

declare(strict_types=1);

namespace PureMemcached\Internal;

use PureMemcached\Client\MemcachedConstants;

/**
 * Converts raw meta get responses into decoded PHP values.
 */
final class MetaValueReader
{
    public static function read(MetaReader $reader, int $serializer): DecodedMetaValue
    {
        $result = $reader->readOne(true);
        if (null !== $result->errorMessage) {
            return DecodedMetaValue::failure(MemcachedConstants::RES_PROTOCOL_ERROR, $result->errorMessage);
        }

        if ('EN' === $result->code || 'NF' === $result->code) {
            return DecodedMetaValue::missing($result);
        }

        if ('VA' !== $result->code || null === $result->value) {
            return DecodedMetaValue::failure(MemcachedConstants::RES_FAILURE);
        }

        try {
            $value = ValueCodec::decode($result->value, (int) ($result->getToken('f') ?? '0'), $serializer);
        } catch (\Exception) {
            return DecodedMetaValue::failure(MemcachedConstants::RES_PAYLOAD_FAILURE);
        }

        return DecodedMetaValue::found($result, $value);
    }
}
