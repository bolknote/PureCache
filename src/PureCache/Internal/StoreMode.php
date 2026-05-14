<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * Backend-agnostic store-operation discriminator passed to
 * {@see \PureCache\AbstractCacheClient::doStore()}. Replaces the legacy
 * magic-string {@code 'S'/'E'/'R'/'A'/'P'} memcached-meta tokens that used to
 * leak into the abstract API.
 *
 * The enum's string value is intentionally aligned with the memcached meta
 * protocol's {@code M<mode>} token (see protocol.txt) so the Memcached client
 * can pass {@code $mode->value} straight to {@see \PureCache\Memcached\Internal\MetaCommandBuilder::metaStore()}
 * without an extra translation table. Non-memcached backends should treat the
 * enum case itself as the source of truth.
 */
enum StoreMode: string
{
    /** Unconditional write ({@code Memcached::set()}, {@code Memcached::setMulti()}, {@code Memcached::cas()}). */
    case Set = 'S';

    /** Write only when the key is currently absent ({@code Memcached::add()}). */
    case Add = 'E';

    /** Write only when the key currently exists ({@code Memcached::replace()}). */
    case Replace = 'R';

    /** Concatenate the value onto an existing key ({@code Memcached::append()}). */
    case Append = 'A';

    /** Prepend the value to an existing key ({@code Memcached::prepend()}). */
    case Prepend = 'P';

    public function isConcatenation(): bool
    {
        return self::Append === $this || self::Prepend === $this;
    }
}
