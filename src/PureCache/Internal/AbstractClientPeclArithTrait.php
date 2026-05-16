<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * PECL increment/decrement surface shared by {@see \PureCache\AbstractCacheClient}.
 *
 * @phpstan-require-extends \PureCache\AbstractCacheClient
 */
trait AbstractClientPeclArithTrait
{
    /**
     * Memcached-style arithmetic primitive. {@code $autoCreate} mirrors PECL's
     * "did the user pass initial/expiry?" detection.
     */
    abstract protected function doArith(string $key, int $offset, bool $decrement, ?string $serverKey, int $initialValue, int $expiry, bool $autoCreate = false): int|false;

    #[\Override]
    public function increment(string $key, int $offset = 1, int $initial_value = 0, int $expiry = 0): int|false
    {
        return $this->doArith($key, $offset, false, null, $initial_value, $expiry, \func_num_args() >= 3);
    }

    #[\Override]
    public function decrement(string $key, int $offset = 1, int $initial_value = 0, int $expiry = 0): int|false
    {
        return $this->doArith($key, $offset, true, null, $initial_value, $expiry, \func_num_args() >= 3);
    }

    #[\Override]
    public function incrementByKey(string $server_key, string $key, int $offset = 1, int $initial_value = 0, int $expiry = 0): int|false
    {
        return $this->doArith($key, $offset, false, $server_key, $initial_value, $expiry, \func_num_args() >= 4);
    }

    #[\Override]
    public function decrementByKey(string $server_key, string $key, int $offset = 1, int $initial_value = 0, int $expiry = 0): int|false
    {
        return $this->doArith($key, $offset, true, $server_key, $initial_value, $expiry, \func_num_args() >= 4);
    }
}
