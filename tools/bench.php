<?php

declare(strict_types=1);

/**
 * Quick local throughput probe (not a CI gate).
 *
 * Usage:
 *   php tools/bench.php memcached 127.0.0.1 11211
 *   php tools/bench.php redis 127.0.0.1 6379
 */

require dirname(__DIR__).'/vendor/autoload.php';

use PureCache\ClientFactory;
use PureCache\Memcached\MemcachedClient;

$backend = $argv[1] ?? 'memcached';
$host = $argv[2] ?? '127.0.0.1';
$port = isset($argv[3]) ? (int) $argv[3] : ('redis' === $backend ? 6379 : 11211);
$iterations = isset($argv[4]) ? max(1, (int) $argv[4]) : 5000;

$client = ClientFactory::create($backend);
$client->addServer($host, $port);
$client->setOption(MemcachedClient::OPT_COMPRESSION, false);

$key = 'purecache_bench_'.getmypid();
$payload = str_repeat('x', 128);

$start = hrtime(true);
for ($i = 0; $i < $iterations; ++$i) {
    if (!$client->set($key, $payload, 60)) {
        fwrite(\STDERR, "set failed at iteration {$i}: ".$client->getResultMessage()."\n");
        exit(1);
    }
}

$setNs = hrtime(true) - $start;

$start = hrtime(true);
for ($i = 0; $i < $iterations; ++$i) {
    if ($client->get($key) !== $payload) {
        fwrite(\STDERR, "get failed at iteration {$i}: ".$client->getResultMessage()."\n");
        exit(1);
    }
}

$getNs = hrtime(true) - $start;
$client->delete($key);

$setOps = $iterations / ($setNs / 1e9);
$getOps = $iterations / ($getNs / 1e9);

echo sprintf(
    "%s %s:%d — %d iterations, payload %d B\n  set: %.0f ops/s\n  get: %.0f ops/s\n",
    $backend,
    $host,
    $port,
    $iterations,
    strlen($payload),
    $setOps,
    $getOps,
);
