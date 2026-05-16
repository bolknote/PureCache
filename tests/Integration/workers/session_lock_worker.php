<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use PureCache\Memcached\MemcachedClient;
use PureCache\Memcached\Session\MemcachedSessionHandler;

$host = getenv('PURECACHE_HOST');
if (false === $host || '' === $host) {
    $host = '127.0.0.1';
}

$port = getenv('PURECACHE_PORT');
if (false === $port || '' === $port) {
    $port = '11211';
}

$savePath = getenv('PURECACHE_SESSION_SAVE_PATH');
if (false === $savePath || '' === $savePath) {
    fwrite(\STDERR, "missing PURECACHE_SESSION_SAVE_PATH\n");
    exit(2);
}

$sessionId = getenv('PURECACHE_SESSION_ID');
if (false === $sessionId || '' === $sessionId) {
    fwrite(\STDERR, "missing PURECACHE_SESSION_ID\n");
    exit(2);
}

$workerId = getenv('PURECACHE_WORKER_ID');
if (false === $workerId || '' === $workerId) {
    $workerId = '0';
}

ini_set('memcached.sess_locking', '1');
ini_set('memcached.sess_lock_wait_min', '50');
ini_set('memcached.sess_lock_wait_max', '50');
ini_set('memcached.sess_lock_retries', '0');

$client = new MemcachedClient();
$client->addServer($host, (int) $port);
$handler = new MemcachedSessionHandler($client);

if (!$handler->open($savePath, $sessionId)) {
    exit(1);
}

if ('0' !== $workerId) {
    // Let worker 0 acquire the lock before challengers run.
    usleep(400_000);
}

$payload = $handler->read($sessionId);
if (false === $payload) {
    $handler->close();
    exit(1);
}

if ('0' === $workerId) {
    usleep(2_000_000);
}

if (!$handler->write($sessionId, 'worker:'.$workerId)) {
    $handler->close();
    exit(1);
}

$handler->close();

exit(0);
