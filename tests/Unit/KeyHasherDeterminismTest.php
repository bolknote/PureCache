<?php

declare(strict_types=1);

namespace PureMemcached\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PureMemcached\Client\MemcachedClient;

final class KeyHasherDeterminismTest extends TestCase
{
    /**
     * @return iterable<string, array{0:string,1:array<int,int>}>
     */
    public static function hashReferenceProvider(): iterable
    {
        yield 'empty' => ['', [
            MemcachedClient::HASH_DEFAULT => 0,
            MemcachedClient::HASH_MD5 => 3649838548,
            MemcachedClient::HASH_CRC => 0,
            MemcachedClient::HASH_FNV1_64 => 2216829733,
            MemcachedClient::HASH_FNV1A_64 => 2216829733,
            MemcachedClient::HASH_FNV1_32 => 2166136261,
            MemcachedClient::HASH_FNV1A_32 => 2166136261,
            MemcachedClient::HASH_HSIEH => 0,
            MemcachedClient::HASH_MURMUR => 0,
        ]];

        yield 'abc' => ['abc', [
            MemcachedClient::HASH_DEFAULT => 3977453403,
            MemcachedClient::HASH_MD5 => 2555380112,
            MemcachedClient::HASH_CRC => 13604,
            MemcachedClient::HASH_FNV1_64 => 1806675403,
            MemcachedClient::HASH_FNV1A_64 => 88168267,
            MemcachedClient::HASH_FNV1_32 => 1134309195,
            MemcachedClient::HASH_FNV1A_32 => 440920331,
            MemcachedClient::HASH_HSIEH => 3535673738,
            MemcachedClient::HASH_MURMUR => 2064434216,
        ]];

        yield 'libmemcached' => ['libmemcached', [
            MemcachedClient::HASH_DEFAULT => 1771519342,
            MemcachedClient::HASH_MD5 => 3290065282,
            MemcachedClient::HASH_CRC => 24688,
            MemcachedClient::HASH_FNV1_64 => 139425235,
            MemcachedClient::HASH_FNV1A_64 => 3636484123,
            MemcachedClient::HASH_FNV1_32 => 3793876851,
            MemcachedClient::HASH_FNV1A_32 => 1971785211,
            MemcachedClient::HASH_HSIEH => 2483216206,
            MemcachedClient::HASH_MURMUR => 2784248208,
        ]];
    }

    /**
     * @param array<int,int> $expected
     *
     * @dataProvider hashReferenceProvider
     */
    public function testHashAlgorithmsReturnLibhashkitReferenceValues(string $key, array $expected): void
    {
        foreach ($expected as $algorithm => $hash) {
            self::assertSame($hash, \PureMemcached\Internal\KeyHasher::hash($key, $algorithm));
        }
    }

    public function testUnknownAlgorithmFallsBackToDefault(): void
    {
        self::assertSame(
            \PureMemcached\Internal\KeyHasher::hash('abc', MemcachedClient::HASH_DEFAULT),
            \PureMemcached\Internal\KeyHasher::hash('abc', 999),
        );
    }
}
