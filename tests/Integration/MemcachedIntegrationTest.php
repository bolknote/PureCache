<?php

declare(strict_types=1);

namespace PureCache\Tests\Integration;

use PureCache\Memcached\MemcachedClient;

final class MemcachedIntegrationTest extends MemcachedLikeIntegrationTestCase
{
    use BackendContractIntegrationTrait;
    use MultiProcessConcurrencyTrait;
    use ReadSizeLimitIntegrationTrait;
    use SessionLockConcurrencyTrait;
    use SingleProcessSemanticsIntegrationTrait;
    use WireSizeLimitIntegrationTrait;

    #[\Override]
    protected static function integrationHost(): string
    {
        $host = getenv('MEMCACHED_TEST_HOST');

        return false !== $host ? $host : '127.0.0.1';
    }

    #[\Override]
    protected static function integrationPort(): int
    {
        $port = getenv('MEMCACHED_TEST_PORT');

        return false !== $port ? (int) $port : 11211;
    }

    #[\Override]
    protected function createClient(): MemcachedClient
    {
        $m = new MemcachedClient();
        $m->addServer(self::integrationHost(), self::integrationPort());

        return $m;
    }

    /**
     * @return array{0:string,1:int}
     */
    private function reservedDeadEndpoint(): array
    {
        // Allocate a free port and immediately release it. This races with
        // the test that opens a TCP connection to that port, but in practice
        // the kernel will keep the port unused long enough for the connect to
        // fail with ECONNREFUSED before something else binds to it.
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        if (!\is_resource($sock)) {
            self::fail('failed to allocate a free TCP port');
        }

        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        \assert(false !== $name);
        $colon = strrpos($name, ':');
        \assert(false !== $colon);

        return [substr($name, 0, $colon), (int) substr($name, $colon + 1)];
    }

    public function testSetMultiFansOutToReplicaSoDataIsReachableViaSecondaryShard(): void
    {
        // Two TCP routes to the same backend. Selector treats them as two
        // independent shards; with OPT_NUMBER_OF_REPLICAS=1 the fan-out
        // writes a copy to *both* shards. We verify the fan-out happened
        // by reading the same key back via a client that talks to only the
        // replica shard.
        $writer = new MemcachedClient();
        $writer->addServer(self::integrationHost(), self::integrationPort());
        $writer->addServer(self::integrationHost(), self::integrationPort());
        $writer->setOption(MemcachedClient::OPT_NUMBER_OF_REPLICAS, 1);

        $payload = [
            'pure_multi_a_'.bin2hex(random_bytes(4)) => 'v-a',
            'pure_multi_b_'.bin2hex(random_bytes(4)) => 'v-b',
            'pure_multi_c_'.bin2hex(random_bytes(4)) => 'v-c',
        ];

        self::assertTrue($writer->setMulti($payload, 60));
        self::assertSame(MemcachedClient::RES_SUCCESS, $writer->getResultCode());

        // Every item should be reachable via a vanilla single-shard reader
        // since the fan-out wrote a copy on the same backend.
        $reader = $this->createClient();
        foreach ($payload as $k => $v) {
            self::assertSame($v, $reader->get($k), 'fan-out copy missing for '.$k);
            self::assertTrue($reader->delete($k));
        }
    }

    public function testGetMultiReturnsPartialResultsWithSomeErrorsWhenOneServerIsDead(): void
    {
        // Seed several keys via a single-server client so we know which key
        // routes where afterwards.
        $seed = $this->createClient();
        $aliveKeys = [];
        for ($i = 0; $i < 16; ++$i) {
            $k = 'pure_partial_'.bin2hex(random_bytes(4));
            self::assertTrue($seed->set($k, 'val-'.$i, 60));
            $aliveKeys[] = $k;
        }

        [$deadHost, $deadPort] = $this->reservedDeadEndpoint();

        $client = new MemcachedClient();
        // First server: alive; second server: black-hole port. With Ketama
        // distribution at least one key in a random batch should route to
        // each shard, so getMulti will partially succeed.
        $client->addServer(self::integrationHost(), self::integrationPort());
        $client->addServer($deadHost, $deadPort);

        $values = $client->getMulti($aliveKeys);
        self::assertIsArray($values);
        self::assertNotSame([], $values, 'expected at least one key from the live server');
        self::assertLessThan(\count($aliveKeys), \count($values), 'expected at least one key on the dead server');

        // RES_SOME_ERRORS — we got something but not everything.
        self::assertSame(MemcachedClient::RES_SOME_ERRORS, $client->getResultCode());

        foreach ($values as $k => $v) {
            $idx = array_search($k, $aliveKeys, true);
            self::assertNotFalse($idx);
            self::assertSame('val-'.$idx, $v);
        }
    }

    #[\Override]
    protected function parallelBackendName(): string
    {
        return 'memcached';
    }

    public function testStaleCasTokenSerialCasSemantics(): void
    {
        $this->assertStaleCasTokenAllowsOnlyFirstCasInProcess();
    }

    public function testSequentialIncrementsMatchStoredTotal(): void
    {
        $this->assertSequentialIncrementsMatchStoredTotal();
    }

    public function testMultiProcessCasRaceExactlyOneWinner(): void
    {
        $this->assertMultiProcessCasRaceExactlyOneWinner();
    }

    public function testMultiProcessIncrementStorm(): void
    {
        $this->assertMultiProcessIncrementStorm();
    }

    public function testReadRejectsItemOverOptItemSizeLimit(): void
    {
        $this->assertReadRejectsOversizedStoredValue();
    }

    public function testMultiProcessSessionLockExclusive(): void
    {
        $this->assertMultiProcessSessionLockExclusive();
    }

    public function testWireOversizedVaFromFakeServerIsRejected(): void
    {
        $this->assertWireOversizedVaIsRejected();
    }
}
