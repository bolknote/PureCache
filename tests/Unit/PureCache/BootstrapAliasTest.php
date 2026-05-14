<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Memcached\MemcachedClient;

/**
 * Validates the {@code bootstrap-alias.php} shim that gives drop-in {@code \Memcached}
 * support to applications that migrate off the PECL extension. The shim is the
 * sole mechanism by which legacy {@code new Memcached()} / {@code Memcached::RES_*}
 * call sites keep working without the real C extension, so it gets its own test
 * separate from the PECL parity suite (which exercises behavior against the real
 * extension when present).
 *
 * All look-ups go through {@see \ReflectionClass} on a runtime-built string so the
 * static analyser can't pre-resolve the global Memcached class to the (possibly
 * loaded) PECL extension and short-circuit the assertions.
 */
final class BootstrapAliasTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (\extension_loaded('memcached')) {
            self::markTestSkipped('PECL memcached is loaded; the global Memcached class belongs to the extension and the shim intentionally does nothing.');
        }

        require_once __DIR__.'/../../../bootstrap-alias.php';
    }

    /**
     * @return class-string
     */
    private function globalClassName(): string
    {
        $name = 'Memcached';
        if (!class_exists($name, false)) {
            self::fail('Expected the global '.$name.' class to be registered before this point (setUpBeforeClass requires the shim)');
        }

        return $name;
    }

    public function testGlobalMemcachedClassIsAliasedToPureCacheClient(): void
    {
        $globalName = $this->globalClassName();

        self::assertTrue(class_exists($globalName, false), 'bootstrap-alias.php must register the global Memcached class when PECL is missing');

        $reflection = new \ReflectionClass($globalName);
        self::assertSame(MemcachedClient::class, $reflection->getName(), 'class_alias resolves the global name to the PureCache class');
    }

    public function testInstantiatingGlobalMemcachedYieldsPureCacheClient(): void
    {
        $globalName = $this->globalClassName();
        $client = new $globalName();

        self::assertInstanceOf(MemcachedClient::class, $client);
    }

    public function testEveryPureCacheConstantIsReachableViaTheGlobalAlias(): void
    {
        $pure = (new \ReflectionClass(MemcachedClient::class))->getConstants();
        $global = (new \ReflectionClass($this->globalClassName()))->getConstants();

        $mismatches = [];
        foreach ($pure as $name => $value) {
            if (!\array_key_exists($name, $global)) {
                $mismatches[] = $name.': missing from global \\Memcached';
                continue;
            }

            if ($global[$name] !== $value) {
                $mismatches[] = \sprintf('%s: alias=%s, source=%s', $name, var_export($global[$name], true), var_export($value, true));
            }
        }

        self::assertSame([], $mismatches, "Alias does not expose the same constants as PureCache\\Memcached\\MemcachedClient:\n".implode("\n", $mismatches));
    }

    public function testLegacyCallSitesUsingPeclConstantNamesStillResolve(): void
    {
        // Smoke-test the handful of constants any real PECL-era code base depends on:
        // result codes, common options, serializer flags. If these break the shim
        // is unusable as a drop-in replacement regardless of what the rest of the
        // surface looks like.
        $names = [
            'RES_SUCCESS', 'RES_NOTFOUND',
            'OPT_PREFIX_KEY', 'OPT_SERIALIZER',
            'SERIALIZER_PHP', 'SERIALIZER_IGBINARY',
            'GET_EXTENDED',
        ];

        $global = (new \ReflectionClass($this->globalClassName()))->getConstants();
        $pure = (new \ReflectionClass(MemcachedClient::class))->getConstants();

        foreach ($names as $name) {
            self::assertArrayHasKey($name, $global, $name.' must be visible on the global alias');
            self::assertArrayHasKey($name, $pure, $name.' must be defined on PureCache MemcachedClient');
            self::assertSame($pure[$name], $global[$name], $name.' must round-trip via the global alias');
        }
    }
}
