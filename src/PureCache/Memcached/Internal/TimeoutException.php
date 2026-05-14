<?php

declare(strict_types=1);

namespace PureCache\Memcached\Internal;

/**
 * Specialised {@see ConnectionException} thrown by {@see StreamConnection}
 * when {@code stream_select}/{@code stream_socket_recvfrom} reports a timeout.
 *
 * Distinguishing timeouts from generic transport failures lets
 * {@see \PureCache\Internal\ServerFailureTracker} apply PECL's separate
 * {@code OPT_SERVER_TIMEOUT_LIMIT} accounting on top of
 * {@code OPT_SERVER_FAILURE_LIMIT}.
 */
final class TimeoutException extends ConnectionException
{
}
