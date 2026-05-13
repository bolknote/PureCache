<?php

declare(strict_types=1);

/**
 * Optional shim: when the memcached extension is not loaded, declares the global
 * Memcached class as an alias of {@see PureMemcached\Client\MemcachedClient}.
 * Import constants from {@see PureMemcached\Client\MemcachedConstants} in application code, not from the global Memcached class.
 */
if (!extension_loaded('memcached')) {
    class_alias(PureMemcached\Client\MemcachedClient::class, 'Memcached', true);
}
