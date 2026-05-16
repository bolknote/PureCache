<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use PureCache\ClientFactory;

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

$rounds = getenv('PURECACHE_ROUNDS');
if (false === $rounds || '' === $rounds) {
    $rounds = '1';
}

if ('' === $key || !ctype_digit($rounds)) {
    fwrite(\STDERR, "missing PURECACHE_KEY or PURECACHE_ROUNDS\n");
    exit(2);
}

$client = ClientFactory::create($backend);
$client->addServer($host, (int) $port);
$roundCount = (int) $rounds;

for ($i = 0; $i < $roundCount; ++$i) {
    $result = $client->increment($key, 1);
    if (!is_int($result)) {
        exit(1);
    }
}

exit(0);
