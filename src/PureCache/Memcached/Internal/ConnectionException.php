<?php

declare(strict_types=1);

namespace PureCache\Memcached\Internal;

class ConnectionException extends \RuntimeException
{
    public static function fromConnectFailure(string $message, ?int $errno): self
    {
        $code = $errno ?? 0;

        return new self($message, $code);
    }

    public function errno(): int
    {
        return $this->getCode();
    }
}
