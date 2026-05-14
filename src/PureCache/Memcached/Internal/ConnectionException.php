<?php

declare(strict_types=1);

namespace PureCache\Memcached\Internal;

class ConnectionException extends \RuntimeException
{
    public function errno(): int
    {
        return $this->getCode();
    }
}
