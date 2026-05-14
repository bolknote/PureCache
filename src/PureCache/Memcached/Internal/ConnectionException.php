<?php

declare(strict_types=1);

namespace PureCache\Memcached\Internal;

final class ConnectionException extends \RuntimeException
{
    public function errno(): int
    {
        return $this->getCode();
    }
}
