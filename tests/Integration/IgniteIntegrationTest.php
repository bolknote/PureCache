<?php

declare(strict_types=1);

namespace PureCache\Tests\Integration;

use PureCache\Ignite\IgniteClient;
use PureCache\Memcached\MemcachedClient;

final class IgniteIntegrationTest extends MemcachedLikeIntegrationTestCase
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

    public function testVersionReportsClusterProductNotThinProtocol(): void
    {
        $m = $this->createClient();
        $v = $m->getVersion();
        self::assertIsArray($v);
        $label = self::integrationHost().':'.self::integrationPort();
        self::assertArrayHasKey($label, $v);
        $version = $v[$label];
        self::assertNotSame('', $version);
        self::assertNotSame('1.2.0', $version, 'must be Ignite cluster VERSION from SYS.NODES, not thin-client protocol level');
        self::assertMatchesRegularExpression('/\d+\.\d+/', $version);

        $stats = $m->getStats();
        self::assertIsArray($stats);
        self::assertArrayHasKey($label, $stats);
        self::assertIsArray($stats[$label]);
        self::assertSame($version, $stats[$label]['version'] ?? null);
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

    #[\Override]
    protected function parallelBackendName(): string
    {
        return 'ignite';
    }

    public function testStaleCasTokenSerialCasSemantics(): void
    {
        $this->assertStaleCasTokenAllowsOnlyFirstCasInProcess();
    }

    public function testSequentialIncrementsMatchStoredTotal(): void
    {
        $this->assertSequentialIncrementsMatchStoredTotal(clientCount: 4, rounds: 15);
    }

    public function testMultiProcessCasRaceExactlyOneWinner(): void
    {
        $this->assertMultiProcessCasRaceExactlyOneWinner();
    }

    public function testMultiProcessIncrementStorm(): void
    {
        $this->assertMultiProcessIncrementStorm(workers: 6, roundsPerWorker: 8);
    }

    public function testReadRejectsItemOverOptItemSizeLimit(): void
    {
        $this->assertReadRejectsOversizedStoredValue();
    }
}
