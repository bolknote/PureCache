<?php

declare(strict_types=1);

namespace PureCache\Tests\Integration;

use PureCache\CacheClient;
use PureCache\Memcached\MemcachedClient;

/**
 * Single-process smoke tests for CAS/increment result codes and stored totals.
 *
 * These loops run sequentially in one PHPUnit process. They do not exercise
 * cross-process races; see {@see MultiProcessConcurrencyTrait} for that.
 */
trait SingleProcessSemanticsIntegrationTrait
{
    abstract protected function createClient(): CacheClient;

    protected function assertStaleCasTokenAllowsOnlyFirstCasInProcess(): void
    {
        $key = 'pure_cas_stale_'.bin2hex(random_bytes(8));

        $writer = $this->createClient();
        self::assertTrue($writer->set($key, 'seed', 60));

        $reader = $this->createClient();
        $ext = $reader->get($key, null, MemcachedClient::GET_EXTENDED);
        self::assertIsArray($ext);
        $cas = $ext['cas'];
        self::assertIsInt($cas);

        $clients = [];
        for ($i = 0; $i < 8; ++$i) {
            $clients[$i] = $this->createClient();
        }

        $successful = 0;
        foreach ($clients as $i => $client) {
            if ($client->cas($cas, $key, 'winner-'.$i, 60)) {
                ++$successful;
            } else {
                self::assertSame(
                    MemcachedClient::RES_DATA_EXISTS,
                    $client->getResultCode(),
                    \sprintf('client %d must observe DATA_EXISTS after the stale CAS token is consumed', $i),
                );
            }
        }

        self::assertSame(1, $successful, 'only the first CAS with a fixed stale token may succeed in one process');

        $writer->delete($key);
    }

    protected function assertSequentialIncrementsMatchStoredTotal(int $clientCount = 6, int $rounds = 20): void
    {
        $key = 'pure_incr_seq_'.bin2hex(random_bytes(8));

        $writer = $this->createClient();
        self::assertTrue($writer->set($key, 0, 60));

        $clients = [];
        for ($i = 0; $i < $clientCount; ++$i) {
            $clients[$i] = $this->createClient();
        }

        $total = 0;
        for ($round = 0; $round < $rounds; ++$round) {
            foreach ($clients as $client) {
                $result = $client->increment($key, 1);
                self::assertIsInt($result);
                ++$total;
            }
        }

        self::assertSame($total, $writer->get($key));
        $writer->delete($key);
    }
}
