<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * Outcome of a single routing decision performed by {@see ServerSelector}.
 *
 * Carrying the {@see ServerAvailability} alongside the resolved {@code index}
 * lets callers (e.g. {@see \PureCache\AbstractCacheClient}) avoid double
 * lookups when they have to surface PECL's
 * {@code RES_SERVER_TEMPORARILY_DISABLED} or {@code RES_NO_SERVERS} verbatim.
 *
 * {@code index === -1} together with {@code status === ServerAvailability::DeadRemoved}
 * means the selector has no usable server at all; the calling site is
 * expected to set {@code RES_NO_SERVERS} and bail out.
 */
final class ServerPick
{
    public function __construct(
        public readonly int $index,
        public readonly ServerAvailability $status = ServerAvailability::Ok,
    ) {
    }

    public function isUsable(): bool
    {
        return $this->index >= 0
            && ServerAvailability::TemporarilyDisabled !== $this->status
            && ServerAvailability::DeadRemoved !== $this->status;
    }
}
