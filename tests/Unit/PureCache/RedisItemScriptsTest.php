<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Redis\RedisItemScripts;

final class RedisItemScriptsTest extends TestCase
{
    public function testDecodePairReplyReturnsStatusAndCas(): void
    {
        self::assertSame([1, '42'], RedisItemScripts::decodePairReply([1, '42']));
        self::assertSame([-2, ''], RedisItemScripts::decodePairReply([-2, '']));
    }

    public function testDecodePairReplyRejectsScalarReply(): void
    {
        $this->expectException(\RuntimeException::class);
        RedisItemScripts::decodePairReply(1);
    }

    public function testDecodePairReplyRejectsWrongLength(): void
    {
        $this->expectException(\RuntimeException::class);
        RedisItemScripts::decodePairReply([1]);
    }

    public function testDecodePairReplyRejectsWrongTypes(): void
    {
        $this->expectException(\RuntimeException::class);
        RedisItemScripts::decodePairReply(['1', '2']);
    }

    public function testDecodeArithReplyReturnsTriple(): void
    {
        self::assertSame([1, '12', '3'], RedisItemScripts::decodeArithReply([1, '12', '3']));
    }

    public function testDecodeArithReplyRejectsShortReply(): void
    {
        $this->expectException(\RuntimeException::class);
        RedisItemScripts::decodeArithReply([1, '12']);
    }

    public function testDecodeArithReplyRejectsWrongTypes(): void
    {
        $this->expectException(\RuntimeException::class);
        RedisItemScripts::decodeArithReply([1, 12, '3']);
    }

    public function testCasSetScriptOnlyMutatesViaHsetExpireOrPersist(): void
    {
        $allowed = ['HGET', 'HSET', 'EXPIRE', 'PERSIST'];
        foreach ($this->extractRedisCalls(RedisItemScripts::LUA_CAS_SET) as $cmd) {
            self::assertContains($cmd, $allowed, 'unexpected redis command in LUA_CAS_SET: '.$cmd);
        }
    }

    public function testReplaceScriptDoesNotEverDeleteOrFlush(): void
    {
        $banned = ['DEL', 'UNLINK', 'FLUSHDB', 'FLUSHALL'];
        foreach ($this->extractRedisCalls(RedisItemScripts::LUA_REPLACE) as $cmd) {
            self::assertNotContains($cmd, $banned, 'unexpected destructive command: '.$cmd);
        }
    }

    public function testArithScriptUsesHincrby(): void
    {
        self::assertContains('HINCRBY', $this->extractRedisCalls(RedisItemScripts::LUA_ARITH));
    }

    public function testArithScriptRefusesAutoCreateWhenNoInitialIsProvided(): void
    {
        // The script must distinguish "key absent + no initial" from "key
        // absent + autovivify": the former returns the {-1, '', ''} miss
        // sentinel that RedisClient maps to RES_NOTFOUND.
        self::assertStringContainsString("if init == nil or init == '' then return {-1, '', ''} end", RedisItemScripts::LUA_ARITH);
    }

    public function testArithScriptSeedsTypedLongOnAutoCreate(): void
    {
        // On autovivify the new entry must be written with the typed-long
        // f-token so subsequent reads decode it as an integer (PECL parity
        // with increment_with_initial).
        self::assertMatchesRegularExpression(
            "/HSET.*?'d', init, 'f', typedF, 'c', '1'/",
            RedisItemScripts::LUA_ARITH,
        );
    }

    public function testArithScriptAppliesExpiryOnAutoCreate(): void
    {
        // When ARGV[4] > 0, the seeded key must receive that TTL.
        self::assertMatchesRegularExpression(
            "/local ttl = tonumber\\(ARGV\\[4\\]\\)\\s+if ttl and ttl > 0 then redis\\.call\\('EXPIRE'/",
            RedisItemScripts::LUA_ARITH,
        );
    }

    public function testArithScriptRefusesNonLongType(): void
    {
        // Existing keys with a non-TYPE_LONG flag (e.g. strings, serialized
        // payloads) must not be incremented — mirrors PECL's NOTSTORED
        // behavior for arith on non-numeric values.
        self::assertStringContainsString('if fn % 16 ~= 1 then return {-2,', RedisItemScripts::LUA_ARITH);
    }

    public function testAppendPrependScriptRefusesNonStringTypeViaCheck(): void
    {
        self::assertStringContainsString('fn % 16 ~= 0', RedisItemScripts::LUA_APPEND_PREPEND);
        self::assertStringContainsString('math.floor(fn / 16) % 2 == 1', RedisItemScripts::LUA_APPEND_PREPEND);
    }

    /**
     * @return list<string>
     */
    private function extractRedisCalls(string $script): array
    {
        $matches = [];
        $count = preg_match_all("/redis\\.call\\(\\s*'([A-Z]+)'/", $script, $matches);
        if (false === $count || 0 === $count) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }
}
