<?php

declare(strict_types=1);

namespace PureCache\Ignite;

/**
 * Thrown when Ignite responds to a request with a non-zero status code.
 * The constructor preserves both the server-side status integer and its
 * accompanying message so the higher-level adapter can map them to
 * {@code MemcachedConstants::RES_*} values.
 */
final class IgniteCommandException extends \RuntimeException
{
}
