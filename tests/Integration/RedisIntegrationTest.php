<?php

declare(strict_types=1);

namespace PureCache\Tests\Integration;

use PureCache\Memcached\MemcachedClient;
use PureCache\Redis\RedisClient;

final class RedisIntegrationTest extends MemcachedLikeIntegrationTestCase
{
    use BackendContractIntegrationTrait;
    use MultiProcessConcurrencyTrait;
    use ReadSizeLimitIntegrationTrait;
    use SingleProcessSemanticsIntegrationTrait;

    protected function contractExpectsFlushDelaySupport(): bool
    {
        return false;
    }

    #[\Override]
    protected static function integrationHost(): string
    {
        $host = getenv('REDIS_TEST_HOST');

        return false !== $host ? $host : '127.0.0.1';
    }

    #[\Override]
    protected static function integrationPort(): int
    {
        $port = getenv('REDIS_TEST_PORT');

        return false !== $port ? (int) $port : 6379;
    }

    private function integrationSecondPort(): int
    {
        $port = getenv('REDIS_TEST_PORT_2');
        if (false !== $port) {
            return (int) $port;
        }

        return self::integrationPort();
    }

    #[\Override]
    protected function createClient(): RedisClient
    {
        $m = new RedisClient();
        $m->addServer(self::integrationHost(), self::integrationPort());

        return $m;
    }

    #[\Override]
    protected function parallelBackendName(): string
    {
        return 'redis';
    }

    public function testStaleCasTokenSerialCasSemantics(): void
    {
        $this->assertStaleCasTokenAllowsOnlyFirstCasInProcess();
    }

    public function testStoredFieldsRemainConsistentAcrossWriters(): void
    {
        $key = 'pure_consistency_'.bin2hex(random_bytes(8));

        $a = $this->createClient();
        $b = $this->createClient();

        self::assertTrue($a->set($key, 'first', 60));

        for ($i = 0; $i < 50; ++$i) {
            self::assertTrue($a->set($key, 'A'.$i, 60));
            self::assertTrue($b->set($key, 'B'.$i, 60));

            $ext = $a->get($key, null, MemcachedClient::GET_EXTENDED);
            self::assertIsArray($ext);
            self::assertIsInt($ext['cas']);
            self::assertGreaterThan(0, $ext['cas']);
            $value = $ext['value'];
            self::assertIsString($value);
            self::assertTrue(
                str_starts_with($value, 'A') || str_starts_with($value, 'B'),
                'value must always be a complete writer payload, never a torn read',
            );
        }

        $a->delete($key);
    }

    public function testIncrementUsesServerSideAtomicArithmetic(): void
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

    public function testCasOnAddedKeyStartsCasFromOne(): void
    {
        $key = 'pure_cas_add_'.bin2hex(random_bytes(8));

        $m = $this->createClient();
        self::assertTrue($m->add($key, 'first', 60));

        $ext = $m->get($key, null, MemcachedClient::GET_EXTENDED);
        self::assertIsArray($ext);
        self::assertSame(1, $ext['cas']);

        self::assertTrue($m->set($key, 'second', 60));
        $ext = $m->get($key, null, MemcachedClient::GET_EXTENDED);
        self::assertIsArray($ext);
        self::assertSame(2, $ext['cas']);

        $m->delete($key);
    }

    public function testByKeyRoutingDistributesAcrossRedisServers(): void
    {
        if ($this->integrationSecondPort() === self::integrationPort()) {
            self::markTestSkipped('second Redis test server is not configured');
        }

        $m = new RedisClient();
        self::assertTrue($m->addServer(self::integrationHost(), self::integrationPort()));
        self::assertTrue($m->addServer(self::integrationHost(), $this->integrationSecondPort()));

        [$routeA, $routeB] = $this->pickDistinctRouteKeys($m);
        $keyA = 'route_a_'.bin2hex(random_bytes(8));
        $keyB = 'route_b_'.bin2hex(random_bytes(8));

        self::assertTrue($m->setByKey($routeA, $keyA, 'value-a', 60));
        self::assertTrue($m->setByKey($routeB, $keyB, 'value-b', 60));
        self::assertSame('value-a', $m->getByKey($routeA, $keyA));
        self::assertSame('value-b', $m->getByKey($routeB, $keyB));

        $serverA = $m->getServerByKey($routeA);
        $serverB = $m->getServerByKey($routeB);
        self::assertIsArray($serverA);
        self::assertIsArray($serverB);
        self::assertNotSame($serverA['port'], $serverB['port']);

        $byPort = [];
        foreach ([self::integrationPort(), $this->integrationSecondPort()] as $port) {
            $single = new RedisClient();
            self::assertTrue($single->addServer(self::integrationHost(), $port));
            $byPort[$port] = $single;
        }

        self::assertSame('value-a', $byPort[$serverA['port']]->get($keyA));
        self::assertSame('value-b', $byPort[$serverB['port']]->get($keyB));
        self::assertFalse($byPort[$serverA['port']]->get($keyB));
        self::assertFalse($byPort[$serverB['port']]->get($keyA));
    }

    /**
     * @return array{0:string,1:string}
     */
    private function pickDistinctRouteKeys(RedisClient $client): array
    {
        $firstRoute = null;
        $firstPort = null;
        for ($i = 0; $i < 2000; ++$i) {
            $route = 'route-'.$i;
            $server = $client->getServerByKey($route);
            if (!\is_array($server)) {
                continue;
            }

            if (null === $firstRoute) {
                $firstRoute = $route;
                $firstPort = $server['port'];
                continue;
            }

            if ($server['port'] !== $firstPort) {
                return [$firstRoute, $route];
            }
        }

        self::fail('failed to find two route keys mapped to different Redis servers');
    }

    public function testRedissConnectionStringUsesTls(): void
    {
        $tlsPort = getenv('REDIS_TLS_TEST_PORT');
        $caFile = getenv('REDIS_TLS_CA_FILE');
        if (!\is_string($tlsPort) || '' === $tlsPort || !\is_string($caFile) || '' === $caFile || !is_file($caFile)) {
            self::markTestSkipped('REDIS_TLS_TEST_PORT and REDIS_TLS_CA_FILE are required for TLS integration');
        }

        $host = self::integrationHost();
        $client = new RedisClient();
        self::assertTrue($client->addServers([[
            'host' => $host,
            'port' => (int) $tlsPort,
            'weight' => 0,
            'tls' => true,
            'tls_ca_file' => $caFile,
        ]]));

        $key = 'pure_rediss_'.bin2hex(random_bytes(8));
        self::assertTrue($client->set($key, 'tls-ok', 60));
        self::assertSame('tls-ok', $client->get($key));
        $client->delete($key);
    }

    public function testReadRejectsItemOverOptItemSizeLimit(): void
    {
        $this->assertReadRejectsOversizedStoredValue();
    }
}
