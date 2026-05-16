<?php

declare(strict_types=1);

namespace PureCache\Tests\Integration;

use PureCache\CacheClient;
use PureCache\Memcached\MemcachedClient;

/**
 * Cross-backend contract tests shared by memcached, Redis, and Ignite clients.
 *
 * Test methods live in this trait so every backend runs the same assertions.
 */
trait BackendContractIntegrationTrait
{
    abstract protected function createClient(): CacheClient;

    /**
     * Memcached supports {@code flush($delay > 0)}; Redis and Ignite return
     * {@see MemcachedClient::RES_NOT_SUPPORTED}.
     */
    protected function contractExpectsFlushDelaySupport(): bool
    {
        return true;
    }

    public function testBackendContractAddRejectsExistingKey(): void
    {
        $key = 'pure_contract_add_'.bin2hex(random_bytes(8));
        $client = $this->createClient();

        self::assertTrue($client->set($key, 'first', 60));
        self::assertFalse($client->add($key, 'second', 60));
        self::assertSame(MemcachedClient::RES_NOTSTORED, $client->getResultCode());

        $client->delete($key);
    }

    public function testBackendContractReplaceRequiresExistingKey(): void
    {
        $key = 'pure_contract_replace_'.bin2hex(random_bytes(8));
        $client = $this->createClient();

        self::assertFalse($client->replace($key, 'nope', 60));
        self::assertContains(
            $client->getResultCode(),
            [MemcachedClient::RES_NOTFOUND, MemcachedClient::RES_NOTSTORED],
            'missing key replace must not succeed',
        );

        self::assertTrue($client->set($key, 'ok', 60));
        self::assertTrue($client->replace($key, 'updated', 60));
        self::assertSame('updated', $client->get($key));

        $client->delete($key);
    }

    public function testBackendContractDeleteMissingKeyIsNotFound(): void
    {
        $key = 'pure_contract_del_miss_'.bin2hex(random_bytes(8));
        $client = $this->createClient();

        self::assertFalse($client->delete($key));
        self::assertSame(MemcachedClient::RES_NOTFOUND, $client->getResultCode());
    }

    public function testBackendContractDelayedDeleteIsUnsupported(): void
    {
        $key = 'pure_contract_del_delay_'.bin2hex(random_bytes(8));
        $client = $this->createClient();
        self::assertTrue($client->set($key, 'x', 60));

        self::assertFalse($client->delete($key, 10));
        self::assertSame(MemcachedClient::RES_NOT_SUPPORTED, $client->getResultCode());

        self::assertTrue($client->delete($key, 0));
    }

    public function testBackendContractPrefixKeyScopesStorage(): void
    {
        $logical = 'pure_contract_prefix_'.bin2hex(random_bytes(8));
        $client = $this->createClient();
        $client->setOption(MemcachedClient::OPT_PREFIX_KEY, 'contract:');

        self::assertTrue($client->set($logical, 'scoped', 60));
        self::assertSame('scoped', $client->get($logical));

        $bare = $this->createClient();
        self::assertFalse($bare->get($logical));
        self::assertSame(MemcachedClient::RES_NOTFOUND, $bare->getResultCode());

        $client->delete($logical);
    }

    public function testBackendContractWriteRejectsPayloadOverItemSizeLimit(): void
    {
        $key = 'pure_contract_write_limit_'.bin2hex(random_bytes(8));
        $client = $this->createClient();
        $client->setOption(MemcachedClient::OPT_COMPRESSION, false);
        $client->setOption(MemcachedClient::OPT_ITEM_SIZE_LIMIT, 32);

        self::assertFalse($client->set($key, str_repeat('x', 64), 60));
        self::assertSame(MemcachedClient::RES_E2BIG, $client->getResultCode());
    }

    public function testBackendContractGetMultiReturnsOnlyExistingKeys(): void
    {
        $present = 'pure_contract_multi_hit_'.bin2hex(random_bytes(8));
        $missing = 'pure_contract_multi_miss_'.bin2hex(random_bytes(8));
        $client = $this->createClient();

        self::assertTrue($client->set($present, 'hit', 60));
        $values = $client->getMulti([$present, $missing]);
        self::assertIsArray($values);
        self::assertArrayHasKey($present, $values);
        self::assertArrayNotHasKey($missing, $values);
        self::assertSame('hit', $values[$present]);

        $client->delete($present);
    }

    public function testBackendContractIncrementSeedsMissingKeyWithInitial(): void
    {
        $key = 'pure_contract_incr_seed_'.bin2hex(random_bytes(8));
        $client = $this->createClient();

        $afterSeed = $client->increment($key, 3, 5, 60);
        self::assertIsInt($afterSeed);
        self::assertEquals($afterSeed, $client->get($key), 'increment return value must match stored counter');

        $client->delete($key);
    }

    public function testBackendContractTouchUpdatesExistingKey(): void
    {
        $key = 'pure_contract_touch_'.bin2hex(random_bytes(8));
        $client = $this->createClient();

        self::assertTrue($client->set($key, 'payload', 60));
        self::assertTrue($client->touch($key, 120));
        self::assertSame('payload', $client->get($key));

        $client->delete($key);
    }

    public function testBackendContractSetMultiSkipsOversizedItemButStoresOthers(): void
    {
        $okKey = 'pure_contract_multi_ok_'.bin2hex(random_bytes(8));
        $bigKey = 'pure_contract_multi_big_'.bin2hex(random_bytes(8));
        $client = $this->createClient();
        $client->setOption(MemcachedClient::OPT_COMPRESSION, false);
        $client->setOption(MemcachedClient::OPT_ITEM_SIZE_LIMIT, 32);

        $client->setMulti([
            $okKey => 'small',
            $bigKey => str_repeat('x', 64),
        ], 60);
        self::assertSame(MemcachedClient::RES_SOME_ERRORS, $client->getResultCode());
        self::assertSame('small', $client->get($okKey));
        self::assertFalse($client->get($bigKey));

        $client->delete($okKey);
    }

    public function testBackendContractFlushDelaySemantics(): void
    {
        $client = $this->createClient();

        if ($this->contractExpectsFlushDelaySupport()) {
            self::assertTrue($client->flush(5));
            self::assertSame(MemcachedClient::RES_SUCCESS, $client->getResultCode());
            self::assertTrue($client->flush(0));
        } else {
            self::assertFalse($client->flush(5));
            self::assertSame(MemcachedClient::RES_NOT_SUPPORTED, $client->getResultCode());
        }
    }
}
