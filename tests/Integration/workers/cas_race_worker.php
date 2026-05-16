<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use PureCache\ClientFactory;
use PureCache\Memcached\MemcachedClient;

$backend = getenv('PURECACHE_BACKEND');
if (false === $backend || '' === $backend) {
    $backend = 'memcached';
}

$host = getenv('PURECACHE_HOST');
if (false === $host || '' === $host) {
    $host = '127.0.0.1';
}

$port = getenv('PURECACHE_PORT');
if (false === $port || '' === $port) {
    $port = '11211';
}

$key = getenv('PURECACHE_KEY');
if (false === $key || '' === $key) {
    $key = '';
}

$cas = getenv('PURECACHE_CAS');
if (false === $cas || '' === $cas) {
    $cas = '';
}

$workerId = getenv('PURECACHE_WORKER_ID');
if (false === $workerId || '' === $workerId) {
    $workerId = '0';
}

if ('' === $key || '' === $cas || !ctype_digit($cas)) {
    fwrite(\STDERR, "missing PURECACHE_KEY or PURECACHE_CAS\n");
    exit(2);
}

$client = ClientFactory::create($backend);
$client->addServer($host, (int) $port);
$client->cas((int) $cas, $key, 'winner-'.$workerId, 60);

exit(MemcachedClient::RES_SUCCESS === $client->getResultCode() ? 0 : 1);
