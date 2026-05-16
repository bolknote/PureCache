<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * Shared Psalm aliases for PECL-shaped cache values and wire-protocol replies.
 *
 * @psalm-type CacheItemValue = mixed
 * @psalm-type CacheItemMap = array<string, CacheItemValue>
 * @psalm-type ClientOptionsMap = array<int, mixed>
 * @psalm-type RedisReply = null|int|string|list<mixed>|array<string, mixed>
 *
 * @psalm-suppress UnusedClass
 */
final class PsalmTypes
{
    private function __construct()
    {
    }
}
