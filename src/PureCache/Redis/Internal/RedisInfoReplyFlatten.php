<?php

declare(strict_types=1);

namespace PureCache\Redis\Internal;

/**
 * Normalizes Redis {@code INFO} replies: Redis 6+ uses nested arrays per section ({@code # Server}),
 * older servers return a flat key/value map.
 *
 * @internal
 */
final class RedisInfoReplyFlatten
{
    private function __construct()
    {
    }

    /**
     * @param array<mixed, mixed> $reply
     *
     * @return array<string, string>
     */
    public static function toStringMap(array $reply): array
    {
        $out = [];
        foreach ($reply as $k => $v) {
            if (!\is_string($k)) {
                continue;
            }

            if (\is_array($v)) {
                foreach ($v as $sk => $sv) {
                    if (!\is_string($sk)) {
                        continue;
                    }

                    if (\is_bool($sv)) {
                        continue;
                    }

                    if (\is_scalar($sv) || null === $sv) {
                        $out[$sk] = (string) $sv;
                    }
                }

                continue;
            }

            if (\is_bool($v)) {
                continue;
            }

            if (\is_scalar($v) || null === $v) {
                $out[$k] = (string) $v;
            }
        }

        return $out;
    }
}
