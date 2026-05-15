<?php

declare(strict_types=1);

namespace PureCache\Ignite\Internal;

/**
 * Typed transport fault; used to decide reconnect+retry without string matching.
 */
enum IgniteTransportFailure
{
    case NotConnected;
    case WriteFailed;
    case WriteTimedOut;
    case ReadTruncated;
    case ReadTimedOut;
    case ConnectionClosed;
    case FrameLengthInvalid;
    case FrameLengthExceeded;
    case ReplyTooShort;
    case RequestIdMismatch;
    case ConnectFailed;
    case HandshakeFailed;
    case RetryExhausted;

    public function defaultMessage(): string
    {
        return match ($this) {
            self::NotConnected => 'Ignite not connected',
            self::WriteFailed => 'Ignite write failed',
            self::WriteTimedOut => 'Ignite write timed out',
            self::ReadTruncated => 'Ignite read truncated',
            self::ReadTimedOut => 'Ignite read timed out',
            self::ConnectionClosed => 'Ignite connection closed by peer',
            self::FrameLengthInvalid => 'Ignite reply: invalid frame length',
            self::FrameLengthExceeded => 'Ignite reply: frame length exceeds maximum',
            self::ReplyTooShort => 'Ignite reply too short',
            self::RequestIdMismatch => 'Ignite reply request id mismatch',
            self::ConnectFailed => 'Ignite connect failed',
            self::HandshakeFailed => 'Ignite handshake failed',
            self::RetryExhausted => 'Ignite transport retry exhausted',
        };
    }
}
