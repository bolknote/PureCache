<?php

declare(strict_types=1);

namespace PureCache\Ignite\Internal;

/**
 * TCP / framing failure on the Ignite thin-client socket (not a command status).
 */
final class IgniteTransportException extends \RuntimeException
{
    public function __construct(
        public readonly IgniteTransportFailure $reason,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct('' !== $message ? $message : $reason->defaultMessage(), 0, $previous);
    }

    public static function connectFailed(string $errstr, int $errno): self
    {
        return new self(
            IgniteTransportFailure::ConnectFailed,
            'Ignite connect failed: '.$errstr.' ('.$errno.')',
        );
    }

    public static function frameLengthInvalid(): self
    {
        return new self(IgniteTransportFailure::FrameLengthInvalid);
    }

    public static function frameLengthExceeded(int $length, int $max): self
    {
        return new self(
            IgniteTransportFailure::FrameLengthExceeded,
            'Ignite reply: frame length '.$length.' exceeds maximum '.$max,
        );
    }

    public static function handshakeFailed(string $message): self
    {
        return new self(IgniteTransportFailure::HandshakeFailed, $message);
    }
}
