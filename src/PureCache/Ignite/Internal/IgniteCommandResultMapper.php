<?php

declare(strict_types=1);

namespace PureCache\Ignite\Internal;

use PureCache\MemcachedConstants;

/**
 * Maps Ignite thin-client {@link ClientStatus} integers to PECL result codes.
 *
 * @see https://github.com/apache/ignite/blob/master/modules/core/src/main/java/org/apache/ignite/internal/processors/platform/client/ClientStatus.java
 */
final class IgniteCommandResultMapper
{
    private function __construct()
    {
    }

    public static function toResultCode(int $statusCode): int
    {
        return match ($statusCode) {
            IgniteProtocol::STATUS_SECURITY_VIOLATION,
            IgniteProtocol::STATUS_AUTH_FAILED => MemcachedConstants::RES_AUTH_FAILURE,
            IgniteProtocol::STATUS_NODE_IN_RECOVERY_MODE,
            IgniteProtocol::STATUS_INVALID_NODE_STATE => MemcachedConstants::RES_SERVER_TEMPORARILY_DISABLED,
            IgniteProtocol::STATUS_RESOURCE_DOES_NOT_EXIST => MemcachedConstants::RES_NOTFOUND,
            IgniteProtocol::STATUS_CACHE_DOES_NOT_EXIST => MemcachedConstants::RES_DATA_DOES_NOT_EXIST,
            default => MemcachedConstants::RES_FAILURE,
        };
    }

    public static function transportToResultCode(IgniteTransportFailure $reason): int
    {
        return match ($reason) {
            IgniteTransportFailure::ReadTimedOut => MemcachedConstants::RES_TIMEOUT,
            IgniteTransportFailure::ConnectFailed,
            IgniteTransportFailure::HandshakeFailed,
            IgniteTransportFailure::ConnectionClosed,
            IgniteTransportFailure::NotConnected => MemcachedConstants::RES_CONNECTION_FAILURE,
            IgniteTransportFailure::ReadTruncated,
            IgniteTransportFailure::WriteFailed => MemcachedConstants::RES_READ_FAILURE,
            default => MemcachedConstants::RES_FAILURE,
        };
    }
}
