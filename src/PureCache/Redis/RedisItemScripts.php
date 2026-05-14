<?php

declare(strict_types=1);

namespace PureCache\Redis;

/**
 * Lua scripts that perform the Memcached-style item mutations atomically on a
 * Redis server.
 *
 * The Redis-backed client stores each item in a hash with three fields:
 *  - `d`: encoded payload bytes (see {@see \PureCache\Internal\ValueCodec})
 *  - `f`: memcached F-token (type bits + compression + user flags)
 *  - `c`: monotonically increasing CAS token (decimal string)
 *
 * Each script collapses what was previously several round-trips (check + write)
 * into one EVAL execution, so concurrent clients cannot observe interleavings.
 */
final class RedisItemScripts
{
    public const int STATUS_OK = 1;

    public const int STATUS_DATA_EXISTS = 0;

    public const int STATUS_NOT_FOUND = -1;

    public const int STATUS_NOT_STORED = -2;

    public const int STATUS_TYPE_INCOMPATIBLE = -3;

    /**
     * Unconditional / CAS set.
     *
     * ARGV[1] = payload (d)
     * ARGV[2] = flags  (f, decimal string)
     * ARGV[3] = ttl    (decimal seconds; {@code <= 0} or missing clears TTL via {@code PERSIST})
     * ARGV[4] = expected CAS (empty string disables the CAS check)
     *
     * Reply: {status, newCas}.
     */
    public const string LUA_CAS_SET = <<<'LUA'
        local cur = redis.call('HGET', KEYS[1], 'c')
        local expectCas = ARGV[4]
        local nc
        if cur == false then
          if expectCas ~= '' then return {-1, ''} end
          nc = '1'
        else
          if expectCas ~= '' and cur ~= expectCas then return {0, ''} end
          local n = tonumber(cur)
          if n == nil then nc = '1' else nc = tostring(n + 1) end
        end
        redis.call('HSET', KEYS[1], 'd', ARGV[1], 'f', ARGV[2], 'c', nc)
        local ttl = tonumber(ARGV[3])
        if ttl and ttl > 0 then redis.call('EXPIRE', KEYS[1], ttl) else redis.call('PERSIST', KEYS[1]) end
        return {1, nc}
        LUA;

    /**
     * Memcached "add" — store only if the key does not exist.
     *
     * ARGV[1] = payload (d)
     * ARGV[2] = flags  (f, decimal string)
     * ARGV[3] = ttl    (decimal seconds; {@code <= 0} clears TTL via {@code PERSIST})
     *
     * Reply: {status, newCas}.
     */
    public const string LUA_ADD = <<<'LUA'
        if redis.call('EXISTS', KEYS[1]) == 1 then return {-2, ''} end
        redis.call('HSET', KEYS[1], 'd', ARGV[1], 'f', ARGV[2], 'c', '1')
        local ttl = tonumber(ARGV[3])
        if ttl and ttl > 0 then redis.call('EXPIRE', KEYS[1], ttl) else redis.call('PERSIST', KEYS[1]) end
        return {1, '1'}
        LUA;

    /**
     * Memcached "replace" — store only if the key exists.
     *
     * ARGV[1] = payload (d)
     * ARGV[2] = flags  (f, decimal string)
     * ARGV[3] = ttl    (decimal seconds; {@code <= 0} clears TTL via {@code PERSIST})
     *
     * Reply: {status, newCas}.
     */
    public const string LUA_REPLACE = <<<'LUA'
        local cur = redis.call('HGET', KEYS[1], 'c')
        if cur == false then return {-2, ''} end
        local n = tonumber(cur)
        local nc
        if n == nil then nc = '1' else nc = tostring(n + 1) end
        redis.call('HSET', KEYS[1], 'd', ARGV[1], 'f', ARGV[2], 'c', nc)
        local ttl = tonumber(ARGV[3])
        if ttl and ttl > 0 then redis.call('EXPIRE', KEYS[1], ttl) else redis.call('PERSIST', KEYS[1]) end
        return {1, nc}
        LUA;

    /**
     * Memcached "append" / "prepend" performed entirely server-side.
     *
     * The caller must guarantee {@code OPT_COMPRESSION = false} (raw byte
     * payload) and the existing item must be {@code TYPE_STRING}; otherwise
     * STATUS_NOT_STORED is returned.
     *
     * ARGV[1] = piece (raw bytes to append/prepend)
     * ARGV[2] = mode ('A' append, 'P' prepend)
     *
     * Reply: {status, newCas}.
     */
    public const string LUA_APPEND_PREPEND = <<<'LUA'
        local cur = redis.call('HMGET', KEYS[1], 'd', 'f', 'c')
        local d = cur[1]
        local f = cur[2]
        local c = cur[3]
        if d == false or f == false or c == false then return {-2, ''} end
        local fn = tonumber(f)
        if fn == nil then return {-2, ''} end
        if fn % 16 ~= 0 then return {-2, ''} end
        if math.floor(fn / 16) % 2 == 1 then return {-2, ''} end
        local newD
        if ARGV[2] == 'A' then newD = d .. ARGV[1] else newD = ARGV[1] .. d end
        local n = tonumber(c)
        local nc
        if n == nil then nc = '1' else nc = tostring(n + 1) end
        redis.call('HSET', KEYS[1], 'd', newD, 'c', nc)
        return {1, nc}
        LUA;

    /**
     * Atomic increment / decrement that delegates to Redis HINCRBY so that
     * arithmetic happens on the server side with full 64-bit precision.
     *
     * The stored value must be {@code TYPE_LONG} and uncompressed (otherwise
     * STATUS_NOT_STORED is returned). Decrement clamps at 0 to match memcached
     * semantics.
     *
     * Optional autovivify: when {@code ARGV[3]} ("initial") is non-empty the
     * script seeds a missing key with that integer (and the supplied
     * {@code ARGV[5]} F-token + {@code ARGV[4]} TTL) instead of returning
     * NOT_FOUND, mirroring PECL's {@code memcached_increment_with_initial}.
     *
     * ARGV[1] = positive offset (decimal string)
     * ARGV[2] = mode ('I' increment, 'D' decrement)
     * ARGV[3] = initial value (decimal string; empty disables autovivify)
     * ARGV[4] = ttl on autovivify (decimal seconds; 0/empty = no expiry)
     * ARGV[5] = TYPE_LONG f-token (decimal string; only used on autovivify)
     *
     * Reply: {status, newValue, newCas} — newValue is the decimal string
     * representation of the post-mutation integer.
     */
    public const string LUA_ARITH = <<<'LUA'
        local f = redis.call('HGET', KEYS[1], 'f')
        local c = redis.call('HGET', KEYS[1], 'c')
        if f == false or c == false then
          local init = ARGV[3]
          if init == nil or init == '' then return {-1, '', ''} end
          local typedF = ARGV[5]
          if typedF == nil or typedF == '' then typedF = '1' end
          redis.call('HSET', KEYS[1], 'd', init, 'f', typedF, 'c', '1')
          local ttl = tonumber(ARGV[4])
          if ttl and ttl > 0 then redis.call('EXPIRE', KEYS[1], ttl) end
          return {1, init, '1'}
        end
        local fn = tonumber(f)
        if fn == nil then return {-2, '', ''} end
        if fn % 16 ~= 1 then return {-2, '', ''} end
        if math.floor(fn / 16) % 2 == 1 then return {-2, '', ''} end
        local offset = tonumber(ARGV[1])
        if offset == nil then return {-2, '', ''} end
        if ARGV[2] == 'D' then offset = -offset end
        local ok = pcall(function()
          redis.call('HINCRBY', KEYS[1], 'd', offset)
        end)
        if not ok then return {-2, '', ''} end
        local newD = redis.call('HGET', KEYS[1], 'd')
        if ARGV[2] == 'D' and string.sub(newD, 1, 1) == '-' then
          newD = '0'
          redis.call('HSET', KEYS[1], 'd', '0')
        end
        local n = tonumber(c)
        local nc
        if n == nil then nc = '1' else nc = tostring(n + 1) end
        redis.call('HSET', KEYS[1], 'c', nc)
        return {1, newD, nc}
        LUA;

    /**
     * Memcached "touch" — bump TTL only when the key exists.
     *
     * ARGV[1] = ttl (decimal seconds; {@code <= 0} clears TTL via {@code PERSIST})
     *
     * Reply: 1 on hit, 0 on miss.
     */
    public const string LUA_TOUCH = <<<'LUA'
        if redis.call('EXISTS', KEYS[1]) == 0 then return 0 end
        local ttl = tonumber(ARGV[1])
        if ttl and ttl > 0 then redis.call('EXPIRE', KEYS[1], ttl) else redis.call('PERSIST', KEYS[1]) end
        return 1
        LUA;

    /**
     * Normalizes a {@code {status, ...}} EVAL reply into a (status, newCas)
     * pair. The optional `$expectedLength` argument lets callers validate
     * scripts that return additional payload fields (e.g. {@see LUA_ARITH}).
     *
     * @return array{0:int, 1:string}
     */
    public static function decodePairReply(mixed $reply, int $expectedLength = 2): array
    {
        if (!\is_array($reply) || \count($reply) < $expectedLength) {
            throw new \RuntimeException('unexpected EVAL reply shape');
        }

        $status = $reply[0];
        $cas = $reply[1];
        if (!\is_int($status) || !\is_string($cas)) {
            throw new \RuntimeException('unexpected EVAL reply types');
        }

        return [$status, $cas];
    }

    /**
     * Decodes the three-element reply of {@see LUA_ARITH}.
     *
     * @return array{0:int, 1:string, 2:string} status, new decimal value, new CAS
     */
    public static function decodeArithReply(mixed $reply): array
    {
        if (!\is_array($reply) || \count($reply) < 3) {
            throw new \RuntimeException('unexpected EVAL reply shape');
        }

        $status = $reply[0];
        $value = $reply[1];
        $cas = $reply[2];
        if (!\is_int($status) || !\is_string($value) || !\is_string($cas)) {
            throw new \RuntimeException('unexpected EVAL reply types');
        }

        return [$status, $value, $cas];
    }
}
