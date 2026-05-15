<?php

declare(strict_types=1);

namespace PureCache\Ignite\Internal;

/**
 * Bounds-checked decoding of type-tagged objects inside Ignite response bodies.
 *
 * Every read validates that the span fits inside the buffer so truncated or
 * hostile frames fail fast instead of returning short strings with a runaway
 * offset.
 */
final class IgniteReply
{
    /** Maximum payload size for a single length-prefixed protocol frame. */
    public const int MAX_FRAME_BYTES = 67_108_864;

    public static function assertFrameLength(int $length): void
    {
        if ($length < 0) {
            throw new \RuntimeException('Ignite reply: invalid frame length');
        }

        if ($length > self::MAX_FRAME_BYTES) {
            throw new \RuntimeException('Ignite reply: frame length '.$length.' exceeds maximum '.self::MAX_FRAME_BYTES);
        }
    }

    public static function requireSpan(string $bytes, int $offset, int $span): void
    {
        $total = \strlen($bytes);
        if ($offset < 0 || $span < 0 || $offset > $total || $offset + $span > $total) {
            throw new \RuntimeException('Ignite reply: truncated at offset '.$offset);
        }
    }

    /**
     * @return array{0:?string,1:int} {@code null} value means a protocol NULL object
     */
    public static function readByteArrayObject(string $bytes, int $offset): array
    {
        self::requireSpan($bytes, $offset, 1);
        $type = IgniteWire::unpackUint8($bytes, $offset);
        if (IgniteProtocol::TYPE_NULL === $type) {
            return [null, $offset + 1];
        }

        if (IgniteProtocol::TYPE_BYTE_ARRAY !== $type) {
            throw new \RuntimeException('Ignite reply: expected byte_array, got type '.$type);
        }

        self::requireSpan($bytes, $offset, 5);
        $length = IgniteWire::unpackInt32($bytes, $offset + 1);
        if ($length < 0) {
            throw new \RuntimeException('Ignite reply: negative byte_array length');
        }

        $end = $offset + 5 + $length;
        self::requireSpan($bytes, $offset, 5 + $length);

        return [substr($bytes, $offset + 5, $length), $end];
    }

    /**
     * @return array{0:string,1:int}
     */
    public static function readStringObject(string $bytes, int $offset): array
    {
        self::requireSpan($bytes, $offset, 1);
        $type = IgniteWire::unpackUint8($bytes, $offset);
        if (IgniteProtocol::TYPE_NULL === $type) {
            return ['', $offset + 1];
        }

        if (IgniteProtocol::TYPE_STRING !== $type) {
            throw new \RuntimeException('Ignite reply: expected string, got type '.$type);
        }

        self::requireSpan($bytes, $offset, 5);
        $length = IgniteWire::unpackInt32($bytes, $offset + 1);
        if ($length < 0) {
            throw new \RuntimeException('Ignite reply: negative string length');
        }

        $end = $offset + 5 + $length;
        self::requireSpan($bytes, $offset, 5 + $length);

        return [substr($bytes, $offset + 5, $length), $end];
    }

    /**
     * @return array{0:string|int|null,1:int}
     */
    public static function readDataObject(string $bytes, int $offset): array
    {
        self::requireSpan($bytes, $offset, 1);
        $type = IgniteWire::unpackUint8($bytes, $offset);
        if (IgniteProtocol::TYPE_NULL === $type) {
            return [null, $offset + 1];
        }

        if (IgniteProtocol::TYPE_STRING === $type) {
            return self::readStringObject($bytes, $offset);
        }

        if (IgniteProtocol::TYPE_INT === $type) {
            self::requireSpan($bytes, $offset, 5);

            return [IgniteWire::unpackInt32($bytes, $offset + 1), $offset + 5];
        }

        if (IgniteProtocol::TYPE_LONG === $type) {
            self::requireSpan($bytes, $offset, 9);

            return [IgniteWire::unpackInt64($bytes, $offset + 1), $offset + 9];
        }

        if (IgniteProtocol::TYPE_BYTE_ARRAY === $type) {
            return self::readByteArrayObject($bytes, $offset);
        }

        throw new \RuntimeException('Ignite reply: unsupported data object type '.$type);
    }

    public static function readBool(string $bytes, int $offset): bool
    {
        self::requireSpan($bytes, $offset, 1);

        return 1 === IgniteWire::unpackUint8($bytes, $offset);
    }

    /**
     * @return array{0:list<string>, 1:bool}
     */
    public static function readScanPage(string $bytes, int $offset): array
    {
        self::requireSpan($bytes, $offset, 4);
        $rowCount = IgniteWire::unpackInt32($bytes, $offset);
        $offset += 4;
        $keys = [];
        for ($i = 0; $i < $rowCount; ++$i) {
            [$key, $offset] = self::readStringObject($bytes, $offset);
            $keys[] = $key;
            [, $offset] = self::readByteArrayObject($bytes, $offset);
        }

        return [$keys, self::readBool($bytes, $offset)];
    }
}
