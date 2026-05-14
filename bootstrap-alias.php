<?php

declare(strict_types=1);

/**
 * Optional shim: when the memcached extension is not loaded, declares the global
 * {@code Memcached} class as an alias of {@see PureCache\Memcached\MemcachedClient}.
 *
 * Import constants from {@see PureCache\MemcachedConstants} in application code,
 * not from the global {@code Memcached} class.
 *
 * Loading is opt-in — wire it via your application bootstrap (or composer
 * {@code "autoload": { "files": [...] }}) so that the alias is never installed
 * by surprise inside a host process that already exposes the real extension.
 */
if (!extension_loaded('memcached') && !class_exists('Memcached', false)) {
    class_alias(PureCache\Memcached\MemcachedClient::class, 'Memcached', true);
}
