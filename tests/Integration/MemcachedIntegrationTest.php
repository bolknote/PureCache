<?php

declare(strict_types=1);

namespace PureMemcached\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PureMemcached\Client\MemcachedClient;

final class MemcachedIntegrationTest extends TestCase
{
    private static function host(): string
    {
        $host = getenv('MEMCACHED_TEST_HOST');

        return false !== $host ? $host : '127.0.0.1';
    }

    private static function port(): int
    {
        $port = getenv('MEMCACHED_TEST_PORT');

        return false !== $port ? (int) $port : 11211;
    }

    public static function setUpBeforeClass(): void
    {
        $fp = @fsockopen(self::host(), self::port(), $errno, $errstr, 0.5);
        if (!\is_resource($fp)) {
            self::markTestSkipped('memcached not reachable at '.self::host().':'.self::port());
        }

        fclose($fp);
    }

    private function client(): MemcachedClient
    {
        $m = new MemcachedClient();
        $m->addServer(self::host(), self::port());

        return $m;
    }

    private function key(string $prefix): string
    {
        return $prefix.'_'.bin2hex(random_bytes(8));
    }

    private function assertResultCode(MemcachedClient $client, int $expected): void
    {
        self::assertSame($expected, $client->getResultCode());
    }

    private function notFoundCode(): int
    {
        return MemcachedClient::RES_NOTFOUND;
    }

    public function testSetGetDelete(): void
    {
        $m = $this->client();
        $key = $this->key('pure_meta');
        self::assertTrue($m->set($key, ['a' => 1], 60));
        self::assertSame(MemcachedClient::RES_SUCCESS, $m->getResultCode());
        $v = $m->get($key);
        self::assertSame(['a' => 1], $v);
        self::assertTrue($m->delete($key));
        self::assertFalse($m->get($key));
        self::assertSame(MemcachedClient::RES_NOTFOUND, $m->getResultCode());
    }

    public function testCas(): void
    {
        $m = $this->client();
        $key = $this->key('pure_cas');
        $m->set($key, 'v1', 60);
        $ext = $m->get($key, null, MemcachedClient::GET_EXTENDED);
        self::assertIsArray($ext);
        $cas = $ext['cas'];
        self::assertIsInt($cas);
        self::assertTrue($m->cas($cas, $key, 'v2', 60));
        self::assertSame('v2', $m->get($key));
    }

    public function testStaleCasFailsWithDataExists(): void
    {
        $m = $this->client();
        $key = $this->key('pure_cas_stale');

        self::assertTrue($m->set($key, 'v1', 60));
        $ext = $m->get($key, null, MemcachedClient::GET_EXTENDED);
        self::assertIsArray($ext);
        self::assertTrue($m->set($key, 'v2', 60));

        self::assertIsInt($ext['cas']);
        self::assertFalse($m->cas($ext['cas'], $key, 'v3', 60));
        self::assertSame(MemcachedClient::RES_DATA_EXISTS, $m->getResultCode());
        self::assertSame('v2', $m->get($key));
    }

    public function testStaleCasPreservesServerCasToken(): void
    {
        $m = $this->client();
        $key = $this->key('pure_cas_token_preserved');

        self::assertTrue($m->set($key, 'v1', 60));
        $stale = $m->get($key, null, MemcachedClient::GET_EXTENDED);
        self::assertIsArray($stale);
        self::assertTrue($m->set($key, 'v2', 60));

        $afterWriter = $m->get($key, null, MemcachedClient::GET_EXTENDED);
        self::assertIsArray($afterWriter);
        self::assertNotSame($stale['cas'], $afterWriter['cas']);

        self::assertIsInt($stale['cas']);
        self::assertFalse($m->cas($stale['cas'], $key, 'v3', 60));
        self::assertSame(MemcachedClient::RES_DATA_EXISTS, $m->getResultCode());

        $afterFailedCas = $m->get($key, null, MemcachedClient::GET_EXTENDED);
        self::assertIsArray($afterFailedCas);
        self::assertSame('v2', $afterFailedCas['value']);
        self::assertSame($afterWriter['cas'], $afterFailedCas['cas']);
    }

    public function testCasOnMissingKeyReportsNotFound(): void
    {
        $m = $this->client();
        $key = $this->key('pure_cas_missing');

        self::assertTrue($m->set($key, 'v1', 60));
        $ext = $m->get($key, null, MemcachedClient::GET_EXTENDED);
        self::assertIsArray($ext);
        self::assertTrue($m->delete($key));

        self::assertIsInt($ext['cas']);
        self::assertFalse($m->cas($ext['cas'], $key, 'v2', 60));
        self::assertSame(MemcachedClient::RES_NOTFOUND, $m->getResultCode());
        self::assertFalse($m->get($key));
        self::assertSame(MemcachedClient::RES_NOTFOUND, $m->getResultCode());
    }

    public function testStaleCasByKeyFailsWithDataExists(): void
    {
        $m = $this->client();
        $key = $this->key('pure_cas_bykey_stale');

        self::assertTrue($m->setByKey('route-cas', $key, 'v1', 60));
        $stale = $m->getByKey('route-cas', $key, null, MemcachedClient::GET_EXTENDED);
        self::assertIsArray($stale);
        self::assertTrue($m->setByKey('route-cas', $key, 'v2', 60));

        self::assertIsInt($stale['cas']);
        self::assertFalse($m->casByKey($stale['cas'], 'route-cas', $key, 'v3', 60));
        self::assertSame(MemcachedClient::RES_DATA_EXISTS, $m->getResultCode());
        self::assertSame('v2', $m->getByKey('route-cas', $key));
    }

    public function testIncrement(): void
    {
        $m = $this->client();
        $key = $this->key('pure_incr');
        $m->delete($key);
        self::assertFalse($m->increment($key, 2));
        self::assertSame(MemcachedClient::RES_NOTFOUND, $m->getResultCode());
        self::assertFalse($m->increment($key, 1, 0, 60));
        self::assertSame(MemcachedClient::RES_NOTFOUND, $m->getResultCode());
        self::assertTrue($m->set($key, 10, 60));
        self::assertSame(12, $m->increment($key, 2));
        self::assertSame(9, $m->decrement($key, 3));
    }

    public function testVersion(): void
    {
        $m = $this->client();
        $v = $m->getVersion();
        self::assertIsArray($v);
        $label = self::host().':'.self::port();
        self::assertArrayHasKey($label, $v);
        self::assertNotEmpty($v[$label]);
    }

    public function testSetMultiGetMultiAndDeleteMulti(): void
    {
        $m = $this->client();
        $keys = [
            $this->key('pure_multi_a'),
            $this->key('pure_multi_b'),
            $this->key('pure_multi_c'),
        ];

        self::assertTrue($m->setMulti([
            $keys[0] => 'a',
            $keys[1] => 2,
            $keys[2] => ['c' => true],
        ], 60));

        self::assertSame([
            $keys[0] => 'a',
            $keys[1] => 2,
            $keys[2] => ['c' => true],
        ], $m->getMulti($keys, MemcachedClient::GET_PRESERVE_ORDER));

        $deleted = $m->deleteMulti($keys);
        self::assertSame([
            $keys[0] => true,
            $keys[1] => true,
            $keys[2] => true,
        ], $deleted);
    }

    public function testSetMultiContinuesAfterValueFailure(): void
    {
        $m = $this->client();
        $m->setOption(MemcachedClient::OPT_ITEM_SIZE_LIMIT, 20);

        $keys = [
            $this->key('pure_multi_partial_a'),
            $this->key('pure_multi_partial_big'),
            $this->key('pure_multi_partial_b'),
        ];

        self::assertFalse($m->setMulti([
            $keys[0] => 'small-a',
            $keys[1] => str_repeat('x', 64),
            $keys[2] => 'small-b',
        ], 60));
        self::assertSame(MemcachedClient::RES_SOME_ERRORS, $m->getResultCode());

        self::assertSame('small-a', $m->get($keys[0]));
        self::assertFalse($m->get($keys[1]));
        self::assertSame(MemcachedClient::RES_NOTFOUND, $m->getResultCode());
        self::assertSame('small-b', $m->get($keys[2]));
    }

    public function testGetMultiEmptyAndExtendedResults(): void
    {
        $m = $this->client();
        $keys = [$this->key('pure_ext_a'), $this->key('pure_ext_b')];

        self::assertSame([], $m->getMulti([]));
        self::assertSame(MemcachedClient::RES_SUCCESS, $m->getResultCode());

        self::assertTrue($m->setMulti([
            $keys[0] => 'a',
            $keys[1] => 'b',
        ], 60));

        $result = $m->getMulti($keys, MemcachedClient::GET_EXTENDED | MemcachedClient::GET_PRESERVE_ORDER);
        self::assertIsArray($result);
        self::assertIsArray($result[$keys[0]]);
        self::assertIsArray($result[$keys[1]]);
        self::assertSame('a', $result[$keys[0]]['value']);
        self::assertSame('b', $result[$keys[1]]['value']);
        self::assertIsInt($result[$keys[0]]['cas']);
        self::assertIsInt($result[$keys[1]]['cas']);
        self::assertSame(0, $result[$keys[0]]['flags']);
        self::assertSame(0, $result[$keys[1]]['flags']);

        $unordered = $m->getMulti($keys);
        self::assertSame([
            $keys[0] => 'a',
            $keys[1] => 'b',
        ], $unordered);
    }

    public function testAddReplaceAppendPrependAndTouch(): void
    {
        $m = $this->client();
        $m->setOption(MemcachedClient::OPT_COMPRESSION, false);

        $key = $this->key('pure_store');

        self::assertTrue($m->add($key, 'b', 60));
        self::assertFalse($m->add($key, 'ignored', 60));
        self::assertSame(MemcachedClient::RES_NOTSTORED, $m->getResultCode());

        self::assertTrue($m->replace($key, 'b', 60));
        self::assertTrue($m->prepend($key, 'a'));
        self::assertTrue($m->append($key, 'c'));
        self::assertSame('abc', $m->get($key));

        self::assertTrue($m->touch($key, 60));
        self::assertTrue($m->delete($key));
    }

    public function testMissingStoreMutationsReportNotFound(): void
    {
        $m = $this->client();
        $key = $this->key('pure_missing_store');

        self::assertFalse($m->replace($key, 'value', 60));
        self::assertSame(MemcachedClient::RES_NOTSTORED, $m->getResultCode());
        self::assertFalse($m->touch($key, 60));
        self::assertSame(MemcachedClient::RES_NOTFOUND, $m->getResultCode());
        self::assertFalse($m->delete($key));
        $this->assertResultCode($m, MemcachedClient::RES_NOTFOUND);

        $deleted = $m->deleteMulti([$key]);
        self::assertArrayHasKey($key, $deleted);
        self::assertSame($this->notFoundCode(), $deleted[$key]);
    }

    public function testDeleteWithTimeIsExplicitlyUnsupported(): void
    {
        $m = $this->client();
        $key = $this->key('pure_delayed_delete');

        self::assertTrue($m->set($key, 'value', 60));
        self::assertFalse($m->delete($key, 1));
        self::assertSame(MemcachedClient::RES_NOT_SUPPORTED, $m->getResultCode());
        self::assertSame('value', $m->get($key));
    }

    public function testKeysWithSpacesAreRejectedLikePecl(): void
    {
        $m = $this->client();
        $key = $this->key('pure delayed delete');

        self::assertFalse($m->set($key, 'value', 60));
        self::assertSame(MemcachedClient::RES_BAD_KEY_PROVIDED, $m->getResultCode());
        self::assertFalse($m->delete($key, 1));
        self::assertSame(MemcachedClient::RES_BAD_KEY_PROVIDED, $m->getResultCode());
    }

    public function testByKeyOperationsAndPrefix(): void
    {
        $m = $this->client();
        $m->setOption(MemcachedClient::OPT_PREFIX_KEY, 'prefix:');

        $key = $this->key('pure_bykey');
        self::assertTrue($m->setByKey('route-a', $key, 'value', 60));
        self::assertSame('value', $m->getByKey('route-a', $key));
        self::assertTrue($m->deleteByKey('route-a', $key));
    }

    public function testAddByKeyAndReplaceByKeyHonorRoutingAndExistence(): void
    {
        $m = $this->client();
        $key = $this->key('pure_bykey_addreplace');

        self::assertFalse($m->replaceByKey('route-add', $key, 'first', 60));
        self::assertSame(MemcachedClient::RES_NOTSTORED, $m->getResultCode());

        self::assertTrue($m->addByKey('route-add', $key, 'first', 60));
        self::assertFalse($m->addByKey('route-add', $key, 'duplicate', 60));
        self::assertSame(MemcachedClient::RES_NOTSTORED, $m->getResultCode());

        self::assertTrue($m->replaceByKey('route-add', $key, 'second', 60));
        self::assertSame('second', $m->getByKey('route-add', $key));

        self::assertTrue($m->deleteByKey('route-add', $key));
    }

    public function testByKeyMultiCasTouchAndStringMutation(): void
    {
        $m = $this->client();
        $m->setOption(MemcachedClient::OPT_COMPRESSION, false);

        $keys = [$this->key('pure_bykey_a'), $this->key('pure_bykey_b')];

        self::assertTrue($m->setMultiByKey('route-b', [
            $keys[0] => 'b',
            $keys[1] => 'second',
        ], 60));

        self::assertSame([
            $keys[0] => 'b',
            $keys[1] => 'second',
        ], $m->getMultiByKey('route-b', $keys, MemcachedClient::GET_PRESERVE_ORDER));

        $extended = $m->getByKey('route-b', $keys[0], null, MemcachedClient::GET_EXTENDED);
        self::assertIsArray($extended);
        self::assertIsInt($extended['cas']);
        self::assertTrue($m->casByKey($extended['cas'], 'route-b', $keys[0], 'b', 60));
        self::assertTrue($m->prependByKey('route-b', $keys[0], 'a'));
        self::assertTrue($m->appendByKey('route-b', $keys[0], 'c'));
        self::assertSame('abc', $m->getByKey('route-b', $keys[0]));
        $extended = $m->getByKey('route-b', $keys[0], null, MemcachedClient::GET_EXTENDED);
        self::assertIsArray($extended);
        self::assertSame('abc', $extended['value']);
        self::assertIsInt($extended['cas']);
        self::assertSame(0, $extended['flags']);
        self::assertTrue($m->touchByKey('route-b', $keys[0], 60));

        self::assertSame([
            $keys[0] => true,
            $keys[1] => true,
        ], $m->deleteMultiByKey('route-b', $keys));
    }

    public function testMissingByKeyAndInvalidByKeyInputs(): void
    {
        $m = $this->client();
        $key = $this->key('pure_missing_bykey');

        self::assertFalse($m->getByKey('route-missing', $key));
        self::assertSame(MemcachedClient::RES_NOTFOUND, $m->getResultCode());

        $m->setOption(MemcachedClient::OPT_VERIFY_KEY, true);
        self::assertFalse($m->getByKey('bad route', $key));
        self::assertSame(MemcachedClient::RES_BAD_KEY_PROVIDED, $m->getResultCode());
        self::assertFalse($m->getMultiByKey('route', ['bad key']));
        $this->assertResultCode($m, MemcachedClient::RES_BAD_KEY_PROVIDED);
    }

    public function testByKeyArithmeticAndDelayedFetch(): void
    {
        $m = $this->client();
        $counter = $this->key('pure_bykey_counter');
        $keys = [$this->key('pure_delay_key_a'), $this->key('pure_delay_key_b')];

        $m->deleteByKey('route-c', $counter);
        self::assertFalse($m->incrementByKey('route-c', $counter, 2));
        self::assertSame(MemcachedClient::RES_NOTFOUND, $m->getResultCode());
        self::assertFalse($m->incrementByKey('route-c', $counter, 1, 0, 60));
        self::assertSame(MemcachedClient::RES_NOTFOUND, $m->getResultCode());
        self::assertTrue($m->setByKey('route-c', $counter, 5, 60));
        self::assertSame(7, $m->incrementByKey('route-c', $counter, 2));
        self::assertSame(4, $m->decrementByKey('route-c', $counter, 3));

        self::assertTrue($m->setMultiByKey('route-c', [
            $keys[0] => 'a',
            $keys[1] => 'b',
        ], 60));
        self::assertTrue($m->getDelayedByKey('route-c', $keys, true));
        $all = $m->fetchAll();
        self::assertIsArray($all);

        $values = [];
        foreach ($all as $entry) {
            self::assertIsString($entry['key']);
            $values[$entry['key']] = $entry['value'];
        }

        self::assertSame([
            $keys[0] => 'a',
            $keys[1] => 'b',
        ], $values);

        self::assertTrue($m->getDelayed($keys, false));
        $withoutCas = $m->fetchAll();
        self::assertIsArray($withoutCas);
        foreach ($withoutCas as $withoutCasEntry) {
            self::assertArrayNotHasKey('cas', $withoutCasEntry);
            self::assertArrayNotHasKey('flags', $withoutCasEntry);
        }
    }

    public function testKeysWithSpacesAreNotStoredWithMetaBase64Encoding(): void
    {
        $m = $this->client();
        $key = $this->key('pure spaced key');

        self::assertFalse($m->checkKey($key));
        self::assertFalse($m->set($key, 'space-ok', 60));
        self::assertSame(MemcachedClient::RES_BAD_KEY_PROVIDED, $m->getResultCode());
    }

    public function testDelayedFetch(): void
    {
        $m = $this->client();
        $keys = [$this->key('pure_delay_a'), $this->key('pure_delay_b')];

        self::assertTrue($m->set($keys[0], 'a', 60));
        self::assertTrue($m->set($keys[1], 'b', 60));
        self::assertTrue($m->getDelayed($keys, true));

        $all = $m->fetchAll();
        self::assertIsArray($all);
        $values = [];
        foreach ($all as $entry) {
            self::assertArrayHasKey('cas', $entry);
            self::assertIsString($entry['key']);
            $values[$entry['key']] = $entry['value'];
        }

        self::assertSame([
            $keys[0] => 'a',
            $keys[1] => 'b',
        ], $values);
    }

    public function testDelayedValueCallbackReturnsUserFlags(): void
    {
        $m = $this->client();
        $key = $this->key('pure_delay_cb_flags');
        $seen = null;

        self::assertTrue($m->setOption(MemcachedClient::OPT_USER_FLAGS, 7));
        self::assertTrue($m->set($key, 'value', 60));
        self::assertTrue($m->getDelayed([$key], true, static function (MemcachedClient $client, array $item) use (&$seen): void {
            $seen = $item;
        }));

        self::assertIsArray($seen);
        self::assertSame($key, $seen['key']);
        self::assertSame('value', $seen['value']);
        self::assertSame(7, $seen['flags']);
    }

    public function testFetchIteratesDelayedResults(): void
    {
        $m = $this->client();
        $keys = [$this->key('pure_fetch_a'), $this->key('pure_fetch_b')];

        self::assertTrue($m->set($keys[0], 'a', 60));
        self::assertTrue($m->set($keys[1], 'b', 60));
        self::assertTrue($m->getDelayed($keys, true));

        $first = $m->fetch();
        $second = $m->fetch();
        $end = $m->fetch();

        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame(MemcachedClient::RES_END, $m->getResultCode());
        self::assertFalse($end);

        self::assertIsString($first['key']);
        self::assertIsString($second['key']);
        $values = [
            $first['key'] => $first['value'],
            $second['key'] => $second['value'],
        ];
        self::assertSame([
            $keys[0] => 'a',
            $keys[1] => 'b',
        ], $values);
    }

    public function testDelayedFetchPreservesExistingCursorWhenQueueingAnotherBatch(): void
    {
        $m = $this->client();
        $keys = [
            $this->key('pure_delay_queue_a'),
            $this->key('pure_delay_queue_b'),
            $this->key('pure_delay_queue_c'),
            $this->key('pure_delay_queue_d'),
        ];

        foreach ($keys as $i => $key) {
            self::assertTrue($m->set($key, 'v'.$i, 60));
        }

        self::assertTrue($m->getDelayed([$keys[0], $keys[1]], false));
        $first = $m->fetch();
        self::assertIsArray($first);
        self::assertSame($keys[0], $first['key']);

        self::assertTrue($m->getDelayed([$keys[2], $keys[3]], false));
        $rest = $m->fetchAll();
        self::assertIsArray($rest);

        $values = [];
        foreach ($rest as $entry) {
            self::assertIsString($entry['key']);
            $values[$entry['key']] = $entry['value'];
        }

        self::assertSame([
            $keys[1] => 'v1',
            $keys[2] => 'v2',
            $keys[3] => 'v3',
        ], $values);
    }

    public function testCacheCallbackStoresMissingValue(): void
    {
        $m = $this->client();
        $key = $this->key('pure_cb');

        $value = $m->get($key, static function (MemcachedClient $client, string $missingKey, mixed &$value, int &$expiration) use ($key): bool {
            TestCase::assertSame($key, $missingKey);
            $value = ['computed' => true];
            $expiration = 60;

            return true;
        });

        self::assertSame(['computed' => true], $value);
        self::assertSame(['computed' => true], $m->get($key));
    }

    public function testCacheCallbackCanDeclineToStoreValue(): void
    {
        $m = $this->client();
        $key = $this->key('pure_cb_miss');

        $value = $m->get($key, static fn (): bool => false);

        self::assertFalse($value);
        self::assertSame(MemcachedClient::RES_NOTFOUND, $m->getResultCode());
    }

    public function testByKeyCacheCallbackStoresMissingValue(): void
    {
        $m = $this->client();
        $key = $this->key('pure_cb_bykey');

        $value = $m->getByKey('route-cb', $key, static function (MemcachedClient $client, string $missingKey, mixed &$value, int &$expiration) use ($key): bool {
            TestCase::assertSame($key, $missingKey);
            $value = 'computed-by-key';
            $expiration = 60;

            return true;
        });

        self::assertSame('computed-by-key', $value);
        self::assertSame('computed-by-key', $m->getByKey('route-cb', $key));
    }

    public function testDelayedFetchCallbackReceivesValuesGroupedByServer(): void
    {
        $m = $this->client();
        $keys = [$this->key('pure_delay_cb_a'), $this->key('pure_delay_cb_b')];

        self::assertTrue($m->set($keys[0], 'a', 60));
        self::assertTrue($m->set($keys[1], 'b', 60));

        $items = [];
        self::assertTrue($m->getDelayed($keys, true, static function (MemcachedClient $client, array $item) use (&$items): void {
            TestCase::assertSame(MemcachedClient::RES_SUCCESS, $client->getResultCode());
            TestCase::assertArrayHasKey('cas', $item);
            TestCase::assertArrayHasKey('flags', $item);
            TestCase::assertIsString($item['key']);
            $items[$item['key']] = $item['value'];
        }));

        self::assertSame([
            $keys[0] => 'a',
            $keys[1] => 'b',
        ], $items);
    }

    public function testDelayedByKeyCallbackReceivesValues(): void
    {
        $m = $this->client();
        $keys = [$this->key('pure_delay_bykey_cb_a'), $this->key('pure_delay_bykey_cb_b')];

        self::assertTrue($m->setMultiByKey('route-delay-cb', [
            $keys[0] => 'a',
            $keys[1] => 'b',
        ], 60));

        $items = [];
        self::assertTrue($m->getDelayedByKey('route-delay-cb', $keys, false, static function (MemcachedClient $client, array $item) use (&$items): void {
            TestCase::assertSame(MemcachedClient::RES_SUCCESS, $client->getResultCode());
            TestCase::assertArrayNotHasKey('cas', $item);
            TestCase::assertArrayNotHasKey('flags', $item);
            TestCase::assertIsString($item['key']);
            $items[$item['key']] = $item['value'];
        }));

        self::assertSame([
            $keys[0] => 'a',
            $keys[1] => 'b',
        ], $items);
    }

    public function testJsonSerializerRoundTripOverWire(): void
    {
        $m = $this->client();
        $m->setOption(MemcachedClient::OPT_SERIALIZER, MemcachedClient::SERIALIZER_JSON_ARRAY);

        $key = $this->key('pure_json');

        self::assertTrue($m->set($key, ['json' => ['ok' => true]], 60));
        self::assertSame(['json' => ['ok' => true]], $m->get($key));
    }

    public function testIgbinarySerializerRoundTripOverWire(): void
    {
        if (!\extension_loaded('igbinary')) {
            self::markTestSkipped('igbinary is not loaded');
        }

        $m = $this->client();
        self::assertTrue($m->setOption(MemcachedClient::OPT_SERIALIZER, MemcachedClient::SERIALIZER_IGBINARY));

        $key = $this->key('pure_igbinary');
        $value = [
            'list' => [1, 2, 3],
            'assoc' => ['enabled' => true, 'name' => 'igbinary'],
            'nested' => [['a' => 1], ['b' => null]],
        ];

        self::assertTrue($m->set($key, $value, 60));
        self::assertSame($value, $m->get($key));
        self::assertSame(MemcachedClient::SERIALIZER_IGBINARY, $m->getOption(MemcachedClient::OPT_SERIALIZER));
    }

    public function testCompressedValueRoundTripOverWire(): void
    {
        if (!\function_exists('gzcompress')) {
            self::markTestSkipped('zlib is not available');
        }

        $m = $this->client();
        $m->setOption(MemcachedClient::OPT_COMPRESSION, true);
        $m->setOption(MemcachedClient::OPT_COMPRESSION_TYPE, MemcachedClient::COMPRESSION_ZLIB);

        $key = $this->key('pure_compressed');
        $value = str_repeat('compressible-', 400);

        self::assertTrue($m->set($key, $value, 60));
        self::assertSame($value, $m->get($key));
    }

    public function testBufferedWritesFlushBeforeRead(): void
    {
        $m = $this->client();
        $m->setOption(MemcachedClient::OPT_BUFFER_WRITES, true);

        $key = $this->key('pure_buffered');

        self::assertTrue($m->set($key, 'buffered', 60));
        self::assertSame('buffered', $m->get($key));

        self::assertTrue($m->set($key, 'updated', 60));
        self::assertTrue($m->flushBuffers());
        self::assertSame('updated', $m->get($key));
    }

    public function testNoReplyBufferedWritesFlushBeforeRead(): void
    {
        $m = $this->client();
        $m->setOption(MemcachedClient::OPT_NOREPLY, true);
        $m->setOption(MemcachedClient::OPT_BUFFER_WRITES, true);

        $key = $this->key('pure_noreply_buffered');

        self::assertTrue($m->set($key, 'queued', 60));
        self::assertSame(MemcachedClient::RES_SUCCESS, $m->getResultCode());
        self::assertSame('queued', $m->get($key));

        self::assertTrue($m->delete($key));
        self::assertFalse($m->get($key));
        self::assertSame(MemcachedClient::RES_NOTFOUND, $m->getResultCode());
    }

    public function testHashWithPrefixKeyStillRoutesAndStores(): void
    {
        $m = $this->client();
        $m->addServer(self::host(), self::port());
        $m->setOption(MemcachedClient::OPT_PREFIX_KEY, 'hash-prefix:');
        $m->setOption(MemcachedClient::OPT_HASH_WITH_PREFIX_KEY, true);

        $key = $this->key('pure_hash_prefix');

        self::assertTrue($m->set($key, 'value', 60));
        self::assertSame('value', $m->get($key));
    }

    public function testPeclStyleConfigurationWithNoBlockStillStoresValues(): void
    {
        $m = $this->client();

        self::assertTrue($m->setOptions([
            MemcachedClient::OPT_PREFIX_KEY => 'cfg:',
            MemcachedClient::OPT_NO_BLOCK => true,
            MemcachedClient::OPT_RECV_TIMEOUT => 3000,
            MemcachedClient::OPT_SEND_TIMEOUT => 1000,
            MemcachedClient::OPT_TCP_NODELAY => true,
            MemcachedClient::OPT_COMPRESSION => true,
            MemcachedClient::OPT_SERIALIZER => MemcachedClient::SERIALIZER_PHP,
            MemcachedClient::OPT_LIBKETAMA_COMPATIBLE => true,
        ]));

        $key = $this->key('pure_pecl_config');

        self::assertTrue($m->set($key, ['configured' => true], 60));
        self::assertSame(['configured' => true], $m->get($key));
        self::assertSame(1, $m->getOption(MemcachedClient::OPT_NO_BLOCK));
    }

    public function testStats(): void
    {
        $m = $this->client();
        $stats = $m->getStats();

        self::assertIsArray($stats);
        $label = self::host().':'.self::port();
        self::assertArrayHasKey($label, $stats);
        self::assertIsArray($stats[$label]);
        self::assertArrayHasKey('version', $stats[$label]);
    }

    public function testStatsItemsAllKeysAndFlush(): void
    {
        $m = $this->client();
        $keys = [$this->key('pure_keys_a'), $this->key('pure_keys_b')];

        self::assertTrue($m->set($keys[0], 'a', 60));
        self::assertTrue($m->set($keys[1], 'b', 60));

        $items = $m->getStats('items');
        self::assertIsArray($items);
        $label = self::host().':'.self::port();
        self::assertArrayHasKey($label, $items);
        self::assertIsArray($items[$label]);
        foreach ($items[$label] as $statValue) {
            self::assertTrue(\is_int($statValue) || \is_float($statValue) || \is_string($statValue));
        }

        $allKeys = $m->getAllKeys();
        self::assertIsArray($allKeys);

        self::assertTrue($m->flush());
        self::assertFalse($m->get($keys[0]));
        self::assertSame(MemcachedClient::RES_NOTFOUND, $m->getResultCode());
    }
}
