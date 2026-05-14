<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * PECL-style server list {@code type} field for {@see \PureCache\CacheClient::getServerList()}.
 */
final class ServerEndpoint
{
    public static function listType(string $host): string
    {
        if ('' !== $host && ('/' === $host[0] || ('.' === $host[0] && str_contains($host, '/')))) {
            return 'SOCKET';
        }

        return 'TCP';
    }
}
