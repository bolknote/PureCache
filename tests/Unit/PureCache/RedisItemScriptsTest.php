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
