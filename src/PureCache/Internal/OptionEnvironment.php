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

    /**
     * Hard byte-length limit this backend applies to keys (and, transitively,
     * to {@code OPT_PREFIX_KEY}). Memcached returns the protocol-mandated
     * {@code KEY_MAX_LENGTH = 250}; Redis/Ignite-backed clients widen it.
     */
    public function maxKeyLength(): int;

    /**
     * Optional pre-applier hook for backend-specific options.
     *
     * Implementations may either:
     *  - return a {@see ClientOptionResult} to short-circuit the default applier
     *    (e.g. backend-private {@code OPT_*} constants, post-apply re-connection,
     *    custom validation rules), or
     *  - return {@code null} to let {@see ClientOptionApplier} continue with its
     *    built-in handling for the option.
     *
     * The default implementation in {@see \PureCache\AbstractCacheClient} always
     * returns {@code null}; concrete clients override it only when they own
     * options that the shared applier does not know about.
     */
    public function applyCustomOption(int $option, mixed $value, ClientCoreState $core): ?ClientOptionResult;
}
