<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * Lifecycle states a server can occupy inside {@see ServerFailureTracker}.
 *
 * The transition diagram mirrors libmemcached's failure machinery:
 *
 *  Ok ──recordFailure×N──► RetryDelayed ──retryTimeout elapsed──► Ok
 *  Ok ──recordFailure×N──► TemporarilyDisabled ──deadTimeout elapsed──► Ok
 *  Ok ──recordFailure×N w/ removeFailed=true──► DeadRemoved
 *
 *  - {@code Ok} — the server is healthy; routing should land on it.
 *  - {@code RetryDelayed} — within the {@code OPT_RETRY_TIMEOUT} window. PECL
 *    routes the next request to the next live server until the window expires.
 *  - {@code TemporarilyDisabled} — within the {@code OPT_DEAD_TIMEOUT} window
 *    after exceeding the failure/timeout limit. PECL's
 *    {@code RES_SERVER_TEMPORARILY_DISABLED}.
 *  - {@code DeadRemoved} — the server has been evicted because
 *    {@code OPT_REMOVE_FAILED_SERVERS=true}. PECL never re-tries; PureCache
 *    treats the slot as missing until the operator re-adds it.
 */
enum ServerAvailability
{
    case Ok;
    case RetryDelayed;
    case TemporarilyDisabled;
    case DeadRemoved;
}
