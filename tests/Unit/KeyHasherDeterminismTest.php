<?php

declare(strict_types=1);

namespace PureMemcached\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PureMemcached\Client\MemcachedClient;
use PureMemcached\Internal\KeyHasher;

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
     */
    #[DataProvider('hashReferenceProvider')]
    public function testHashAlgorithmsReturnLibhashkitReferenceValues(string $key, array $expected): void
    {
        foreach ($expected as $algorithm => $hash) {
            self::assertSame($hash, KeyHasher::hash($key, $algorithm));
        }
    }

    public function testUnknownAlgorithmFallsBackToDefault(): void
    {
        self::assertSame(
            KeyHasher::hash('abc', MemcachedClient::HASH_DEFAULT),
            KeyHasher::hash('abc', 999),
        );
    }

    /**
     * Pinned hsieh values for keys whose length covers every `len & 3` branch of the
     * tail switch (rem=1, rem=2, rem=3, rem=0) and the main 4-byte block loop with
     * one and two iterations. The values are reproducible from libhashkit; see the
     * existing `abc` / `libmemcached` reference cases that validate the algorithm.
     *
     * @return iterable<string, array{0:string,1:int}>
     */
    public static function hsiehLengthProvider(): iterable
    {
        yield 'rem=1 (len=1)' => ['a', 291415938];
        yield 'rem=2 (len=2)' => ['ab', 1366002500];
        yield 'rem=0 single block (len=4)' => ['abcd', 3671636187];
        yield 'rem=1 multi block (len=5)' => ['abcde', 1374488366];
        yield 'rem=2 multi block (len=6)' => ['abcdef', 2520489434];
        yield 'rem=3 multi block (len=7)' => ['abcdefg', 4033987565];
        yield 'rem=0 two blocks (len=8)' => ['abcdefgh', 3188683816];
    }

    #[DataProvider('hsiehLengthProvider')]
    public function testHsiehCoversEveryRemainderBranch(string $key, int $expected): void
    {
        self::assertSame($expected, KeyHasher::hash($key, MemcachedClient::HASH_HSIEH));
        self::assertSame($expected, KeyHasher::hash($key, MemcachedClient::HASH_HSIEH));
    }
}
