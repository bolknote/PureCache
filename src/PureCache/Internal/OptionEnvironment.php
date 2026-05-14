<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * Backend hooks invoked by {@see ClientOptionApplier} when option mutations need
 * to ripple into protocol-level resources (e.g. resetting the memcached connection
 * pool or rebuilding the Redis socket map).
 *
 * The Memcached and Redis clients each implement this interface so the option
 * applier can stay backend-agnostic.
 */
interface OptionEnvironment
{
    /** Drop and lazily rebuild all per-server transport handles. */
    public function onPoolInvalidated(): void;

    /** Force timeout-affecting transport state to be rebuilt. */
    public function onTimeoutsChanged(): void;

    /** Whether this backend rejects the given {@code OPT_*} value entirely. */
    public function isUnsupportedOption(int $option): bool;

    /** Optional human-friendly reason for {@see isUnsupportedOption()}. */
    public function unsupportedOptionMessage(): string;
}
