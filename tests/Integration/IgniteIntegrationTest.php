<?php

declare(strict_types=1);

namespace PureCache\Tests\Integration;

use PureCache\Ignite\IgniteClient;
use PureCache\Memcached\MemcachedClient;

final class IgniteIntegrationTest extends MemcachedLikeIntegrationTestCase
{
    #[\Override]
    protected static function integrationHost(): string
    {
        $host = getenv('IGNITE_TEST_HOST');

        return false !== $host && '' !== $host ? $host : '127.0.0.1';
    }

    #[\Override]
    protected static function integrationPort(): int
    {
        $port = getenv('IGNITE_TEST_PORT');

        return false !== $port && '' !== $port ? (int) $port : 10800;
    }

    #[\Override]
    protected function createClient(): IgniteClient
    {
        $client = new IgniteClient();
        $client->addServer(self::integrationHost(), self::integrationPort());

        return $client;
    }

    public function testCasOnAddedKeyExposesStableToken(): void
    {
        $key = 'pure_ig_cas_add_'.bin2hex(random_bytes(8));

        $m = $this->createClient();
        self::assertTrue($m->add($key, 'first', 60));

        $afterAdd = $m->get($key, null, MemcachedClient::GET_EXTENDED);
        self::assertIsArray($afterAdd);
        self::assertIsInt($afterAdd['cas']);
        $casAfterAdd = $afterAdd['cas'];

        self::assertTrue($m->set($key, 'second', 60));
        $afterSet = $m->get($key, null, MemcachedClient::GET_EXTENDED);
        self::assertIsArray($afterSet);
        self::assertIsInt($afterSet['cas']);
        self::assertNotSame($casAfterAdd, $afterSet['cas']);

        self::assertFalse($m->cas($casAfterAdd, $key, 'third', 60));
        self::assertSame(MemcachedClient::RES_DATA_EXISTS, $m->getResultCode());

        $m->delete($key);
    }

    public function testConcurrentCasRaceLetsExactlyOneWriterWin(): void
    {
        $key = 'pure_ig_cas_race_'.bin2hex(random_bytes(8));

        $writer = $this->createClient();
        self::assertTrue($writer->set($key, 'seed', 60));

        $reader = $this->createClient();
        $ext = $reader->get($key, null, MemcachedClient::GET_EXTENDED);
        self::assertIsArray($ext);
        $cas = $ext['cas'];
        self::assertIsInt($cas);

        $racers = [];
        for ($i = 0; $i < 8; ++$i) {
            $racers[$i] = $this->createClient();
        }

        $successful = 0;
        foreach ($racers as $i => $client) {
            if ($client->cas($cas, $key, 'winner-'.$i, 60)) {
                ++$successful;
            } else {
                self::assertSame(
                    MemcachedClient::RES_DATA_EXISTS,
                    $client->getResultCode(),
                    \sprintf('racer %d must observe DATA_EXISTS when losing the CAS', $i),
                );
            }
        }

        self::assertSame(1, $successful, 'exactly one concurrent CAS must succeed for a given stale token');

        $writer->delete($key);
    }

    public function testIncrementUsesOptimisticServerSideArithmetic(): void
    {
        $key = 'pure_ig_incr_atomic_'.bin2hex(random_bytes(8));

        $writer = $this->createClient();
        self::assertTrue($writer->set($key, 0, 60));

        $clients = [];
        for ($i = 0; $i < 4; ++$i) {
            $clients[$i] = $this->createClient();
        }

        $total = 0;
        for ($round = 0; $round < 10; ++$round) {
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
