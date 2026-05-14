<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Value packing compatible with php-memcached (type bits + optional compression prefix).
 */
final class ValueCodec
{
    public const int MASK_TYPE = 0x0F;

    public const int MASK_INTERNAL = 0xFFF0;

    public const int MASK_USER = 0xFFFF0000;

    public const int TYPE_STRING = 0;

    public const int TYPE_LONG = 1;

    public const int TYPE_DOUBLE = 2;

    public const int TYPE_BOOL = 3;

    public const int TYPE_SERIALIZED = 4;

    public const int TYPE_IGBINARY = 5;

    public const int TYPE_JSON = 6;

    public const int TYPE_MSGPACK = 7;

    public const COMPRESSED = 1 << 4;

    public const COMPRESSION_ZLIB = 1 << 5;

    public const COMPRESSION_FASTLZ = 1 << 6;

    public const COMPRESSION_ZSTD = 1 << 7;

    /**
     * Marker bit set on entries produced under
     * {@see MemcachedConstants::ENCODING_MODE_AEAD}. Sits inside
     * {@see MASK_INTERNAL} (bits 4-15) so it round-trips through every
     * backend's flag column. Libmemcached-compat encryption is deliberately
     * markerless — the bit only appears for the modern AEAD format.
     */
    public const ENCRYPTED_AEAD = 1 << 8;

    public static function setType(int &$flags, int $type): void
    {
        $flags = ($flags & ~self::MASK_TYPE) | ($type & self::MASK_TYPE);
    }

    public static function getType(int $flags): int
    {
        return $flags & self::MASK_TYPE;
    }

    public static function setUserFlags(int &$flags, int $userFlags): void
    {
        $flags = ($flags & ~self::MASK_USER) | (($userFlags << 16) & self::MASK_USER);
    }

    public static function getUserFlags(int $flags): int
    {
        return ($flags & self::MASK_USER) >> 16;
    }

    public static function hasCompression(int $flags): bool
    {
        return ($flags & self::COMPRESSED) !== 0;
    }

    public static function hasAeadEncryption(int $flags): bool
    {
        return ($flags & self::ENCRYPTED_AEAD) !== 0;
    }

    public static function compressionKind(int $flags): int
    {
        if (($flags & self::COMPRESSION_ZSTD) !== 0) {
            return MemcachedConstants::COMPRESSION_TYPE_ZSTD;
        }

        if (($flags & self::COMPRESSION_FASTLZ) !== 0) {
            return MemcachedConstants::COMPRESSION_TYPE_FASTLZ;
        }

        return MemcachedConstants::COMPRESSION_TYPE_ZLIB;
    }

    public static function setCompressionFlags(int &$flags, int $compressionType): void
    {
        $flags |= self::COMPRESSED;
        $flags &= ~(self::COMPRESSION_ZLIB | self::COMPRESSION_FASTLZ | self::COMPRESSION_ZSTD);
        match ($compressionType) {
            MemcachedConstants::COMPRESSION_TYPE_ZLIB => $flags |= self::COMPRESSION_ZLIB,
            MemcachedConstants::COMPRESSION_TYPE_FASTLZ => $flags |= self::COMPRESSION_FASTLZ,
            MemcachedConstants::COMPRESSION_TYPE_ZSTD => $flags |= self::COMPRESSION_ZSTD,
            default => $flags |= self::COMPRESSION_ZLIB,
        };
    }

    /**
     * @return array{0: string, 1: int} payload and memcached flags (F token)
     */
    public static function encode(
        mixed $value,
        int $serializer,
        bool $compress,
        int $compressionType,
        int $compressionLevel,
        int $compressionThreshold,
        float $compressionFactor,
        int $userFlags,
        ?EncodingContext $encoding = null,
    ): array {
        $flags = 0;
        if ($userFlags >= 0) {
            self::setUserFlags($flags, $userFlags);
        }

        $payload = self::serializeValue($value, $serializer, $flags);
        $shouldCompress = $compress && \strlen($payload) >= $compressionThreshold && '' !== $payload;
        if ($shouldCompress) {
            [$compressed, $actualType] = self::compressPayload($payload, $compressionType, $compressionLevel);
            if (null !== $compressed) {
                $origLen = \strlen($payload);
                if ($origLen > (\strlen($compressed) * $compressionFactor)) {
                    $packed = pack('V', $origLen).$compressed;
                    self::setCompressionFlags($flags, $actualType);
                    $payload = $packed;
                }
            }
        }

        if ($encoding instanceof EncodingContext) {
            $payload = PayloadEncryption::encrypt($payload, $encoding);
            if (MemcachedConstants::ENCODING_MODE_AEAD === $encoding->mode) {
                $flags |= self::ENCRYPTED_AEAD;
            }
        }

        return [$payload, $flags];
    }

    /**
     * @param bool             $allowSerializedClasses if {@code true},
     *                                                 PHP-serialized objects
     *                                                 are restored to their
     *                                                 original classes, matching
     *                                                 PECL's default behavior.
     *                                                 The {@code false} default
     *                                                 rejects objects via
     *                                                 {@code allowed_classes
     *                                                 => false}, so untrusted
     *                                                 cache values can't be
     *                                                 turned into POPs. Callers
     *                                                 that need full PECL
     *                                                 parity must opt-in
     *                                                 (see
     *                                                 {@code OPT_ALLOW_SERIALIZED_CLASSES}
     *                                                 in {@see ClientOptions}).
     * @param ?EncodingContext $encoding               key+mode set by
     *                                                 {@see \PureCache\AbstractCacheClient::setEncodingKey()}.
     *                                                 {@code null} leaves the
     *                                                 payload as-is unless the
     *                                                 {@see ENCRYPTED_AEAD}
     *                                                 flag is set, in which
     *                                                 case decoding fails
     *                                                 loudly so missing-key
     *                                                 misconfigurations don't
     *                                                 silently return garbage.
     */
    public static function decode(
        string $payload,
        int $flags,
        int $serializer,
        bool $allowSerializedClasses = false,
        ?EncodingContext $encoding = null,
    ): mixed {
        if (self::hasAeadEncryption($flags)) {
            if (!$encoding instanceof EncodingContext || MemcachedConstants::ENCODING_MODE_AEAD !== $encoding->mode) {
                throw new \RuntimeException('encrypted payload (AEAD) but no matching encoding key configured');
            }

            $payload = PayloadEncryption::decrypt($payload, $encoding);
            $flags &= ~self::ENCRYPTED_AEAD;
        } elseif ($encoding instanceof EncodingContext && MemcachedConstants::ENCODING_MODE_LIBMEMCACHED === $encoding->mode) {
            $payload = PayloadEncryption::decrypt($payload, $encoding);
        }

        if (self::hasCompression($flags)) {
            if (\strlen($payload) < 4) {
                throw new \RuntimeException('Invalid compressed payload');
            }

            $packedLength = unpack('V', substr($payload, 0, 4));
            if (false === $packedLength) {
                throw new \RuntimeException('Invalid compressed payload length');
            }

            $orig = $packedLength[1];
            $body = substr($payload, 4);
            $kind = self::compressionKind($flags);
            $payload = self::decompressPayload($body, $kind);
            if (\strlen($payload) !== $orig) {
                throw new \RuntimeException('Invalid decompressed payload length');
            }
        }

        return self::deserializePayload($payload, $flags, $serializer, $allowSerializedClasses);
    }

    private static function serializeValue(mixed $value, int $serializer, int &$flags): string
    {
        return match (true) {
            \is_string($value) => (static function () use ($value, &$flags): string {
                self::setType($flags, self::TYPE_STRING);

                return $value;
            })(),
            \is_int($value) => (static function () use ($value, &$flags): string {
                self::setType($flags, self::TYPE_LONG);

                return (string) $value;
            })(),
            \is_float($value) => (static function () use ($value, &$flags): string {
                self::setType($flags, self::TYPE_DOUBLE);

                return self::formatDouble($value);
            })(),
            true === $value => (static function () use (&$flags): string {
                self::setType($flags, self::TYPE_BOOL);

                return '1';
            })(),
            false === $value => (static function () use (&$flags): string {
                self::setType($flags, self::TYPE_BOOL);

                return '';
            })(),
            default => self::serializeComplex($value, $serializer, $flags),
        };
    }

    private static function serializeComplex(mixed $value, int $serializer, int &$flags): string
    {
        return match ($serializer) {
            MemcachedConstants::SERIALIZER_IGBINARY => (static function () use ($value, &$flags): string {
                if (!\function_exists('igbinary_serialize')) {
                    throw new \RuntimeException('igbinary not available');
                }

                self::setType($flags, self::TYPE_IGBINARY);
                $b = igbinary_serialize($value);
                if (!\is_string($b)) {
                    throw new \RuntimeException('igbinary_serialize failed');
                }

                return $b;
            })(),
            MemcachedConstants::SERIALIZER_JSON, MemcachedConstants::SERIALIZER_JSON_ARRAY => (static function () use ($value, &$flags): string {
                self::setType($flags, self::TYPE_JSON);

                return json_encode($value, \JSON_THROW_ON_ERROR);
            })(),
            MemcachedConstants::SERIALIZER_MSGPACK => (static function () use ($value, &$flags): string {
                if (!\function_exists('msgpack_pack')) {
                    throw new \RuntimeException('msgpack not available');
                }

                self::setType($flags, self::TYPE_MSGPACK);

                return msgpack_pack($value);
            })(),
            default => (static function () use ($value, &$flags): string {
                self::setType($flags, self::TYPE_SERIALIZED);

                return serialize($value);
            })(),
        };
    }

    private static function deserializePayload(string $payload, int $flags, int $serializer, bool $allowSerializedClasses): mixed
    {
        return match (self::getType($flags)) {
            self::TYPE_STRING => $payload,
            self::TYPE_LONG => (int) $payload,
            self::TYPE_DOUBLE => self::parseDouble($payload),
            self::TYPE_BOOL => '' !== $payload && '1' === $payload[0],
            self::TYPE_SERIALIZED => self::phpUnserialize($payload, $allowSerializedClasses),
            self::TYPE_IGBINARY => self::igbinaryUnserialize($payload),
            self::TYPE_JSON => json_decode(
                $payload,
                MemcachedConstants::SERIALIZER_JSON_ARRAY === $serializer,
                512,
                \JSON_THROW_ON_ERROR,
            ),
            self::TYPE_MSGPACK => self::msgpackUnpack($payload),
            default => $payload,
        };
    }

    private static function phpUnserialize(string $payload, bool $allowSerializedClasses): mixed
    {
        $options = $allowSerializedClasses ? [] : ['allowed_classes' => false];
        $value = @unserialize($payload, $options);
        if (false === $value && 'b:0;' !== $payload) {
            throw new \RuntimeException('php unserialize failed');
        }

        return $value;
    }

    private static function igbinaryUnserialize(string $payload): mixed
    {
        if (!\function_exists('igbinary_unserialize')) {
            throw new \RuntimeException('igbinary not available');
        }

        return igbinary_unserialize($payload);
    }

    private static function msgpackUnpack(string $payload): mixed
    {
        if (!\function_exists('msgpack_unpack')) {
            throw new \RuntimeException('msgpack not available');
        }

        return msgpack_unpack($payload);
    }

    /**
     * Locale-independent double serialization.
     *
     * {@code sprintf('%.17G', ...)} and the {@code (float)} cast both honour
     * {@code LC_NUMERIC}, which means a process where {@code setlocale(LC_NUMERIC,'de_DE')}
     * has been called would serialize {@code 1.5} as {@code '1,5'} and then
     * fail to parse it back. We pin LC_NUMERIC to {@code C} for the duration
     * of the conversion to keep the wire format portable across locales.
     */
    private static function formatDouble(float $v): string
    {
        if (is_nan($v)) {
            return 'NaN';
        }

        if (is_infinite($v)) {
            return $v > 0 ? 'Infinity' : '-Infinity';
        }

        $previous = setlocale(\LC_NUMERIC, '0');
        if (\is_string($previous) && 'C' !== $previous) {
            setlocale(\LC_NUMERIC, 'C');
        }

        try {
            return strtoupper(\sprintf('%.17G', $v));
        } finally {
            if (\is_string($previous) && 'C' !== $previous) {
                setlocale(\LC_NUMERIC, $previous);
            }
        }
    }

    private static function parseDouble(string $payload): float
    {
        return match ($payload) {
            'NaN', 'NAN' => \NAN,
            'Infinity', 'INF' => \INF,
            '-Infinity', '-INF' => -\INF,
            default => self::castFloatLocaleIndependent($payload),
        };
    }

    private static function castFloatLocaleIndependent(string $payload): float
    {
        $previous = setlocale(\LC_NUMERIC, '0');
        if (\is_string($previous) && 'C' !== $previous) {
            setlocale(\LC_NUMERIC, 'C');
        }

        try {
            return (float) $payload;
        } finally {
            if (\is_string($previous) && 'C' !== $previous) {
                setlocale(\LC_NUMERIC, $previous);
            }
        }
    }

    /**
     * @return array{0:?string,1:int}
     */
    private static function compressPayload(string $payload, int $compressionType, int $level): array
    {
        if (MemcachedConstants::COMPRESSION_TYPE_ZSTD === $compressionType) {
            if (!\function_exists('zstd_compress')) {
                return [null, $compressionType];
            }

            $out = zstd_compress($payload, $level);
            if (!\is_string($out)) {
                $err = error_get_last();
                throw new \RuntimeException('zstd compress failed: '.($err['message'] ?? 'unknown'));
            }

            return [$out, MemcachedConstants::COMPRESSION_TYPE_ZSTD];
        }

        if (MemcachedConstants::COMPRESSION_TYPE_FASTLZ === $compressionType) {
            if (\function_exists('fastlz_compress')) {
                $out = fastlz_compress($payload);
                if (!\is_string($out)) {
                    $err = error_get_last();
                    throw new \RuntimeException('fastlz compress failed: '.($err['message'] ?? 'unknown'));
                }

                return [$out, MemcachedConstants::COMPRESSION_TYPE_FASTLZ];
            }

            return [null, $compressionType];
        }

        if (MemcachedConstants::COMPRESSION_TYPE_ZLIB === $compressionType) {
            if (!\function_exists('gzcompress')) {
                return [null, $compressionType];
            }

            $l = max(0, min(9, $level));
            $c = gzcompress($payload, $l);
            if (false === $c) {
                $err = error_get_last();
                throw new \RuntimeException('zlib compress failed: '.($err['message'] ?? 'unknown'));
            }

            return [$c, MemcachedConstants::COMPRESSION_TYPE_ZLIB];
        }

        return [null, $compressionType];
    }

    private static function decompressPayload(string $body, int $kind): string
    {
        if (MemcachedConstants::COMPRESSION_TYPE_ZSTD === $kind) {
            if (!\function_exists('zstd_uncompress')) {
                throw new \RuntimeException('zstd not available');
            }

            $out = zstd_uncompress($body);
            if (!\is_string($out)) {
                $err = error_get_last();
                throw new \RuntimeException('zstd decompress failed: '.($err['message'] ?? 'unknown'));
            }

            return $out;
        }

        if (MemcachedConstants::COMPRESSION_TYPE_FASTLZ === $kind) {
            if (!\function_exists('fastlz_decompress')) {
                throw new \RuntimeException('fastlz not available');
            }

            $out = fastlz_decompress($body);
            if (!\is_string($out)) {
                $err = error_get_last();
                throw new \RuntimeException('fastlz decompress failed: '.($err['message'] ?? 'unknown'));
            }

            return $out;
        }

        $out = @gzuncompress($body);
        if (false === $out) {
            $err = error_get_last();
            throw new \RuntimeException('zlib decompress failed: '.($err['message'] ?? 'unknown'));
        }

        return $out;
    }
}
