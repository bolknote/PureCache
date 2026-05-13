<?php

declare(strict_types=1);

namespace PureMemcached\Internal;

use PureMemcached\Client\MemcachedConstants;

final readonly class ClientOptionResult
{
    private function __construct(
        public bool $ok,
        public int $code,
        public ?string $message = null,
    ) {
    }

    public static function success(): self
    {
        return new self(true, MemcachedConstants::RES_SUCCESS);
    }

    public static function failure(int $code, ?string $message = null): self
    {
        return new self(false, $code, $message);
    }
}
