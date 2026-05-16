<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\TestCase;
use PureCache\Memcached\MemcachedClient;
use PureCache\MemcachedConstants;

/**
 * Documents PureCache-only constants that intentionally diverge from PECL {@see \Memcached}.
 */
final class PureCacheExtensionConstantsTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function pureCacheOnlyConstantNames(): array
    {
        return [
            'OPT_ENCODING_MODE',
            'ENCODING_MODE_LIBMEMCACHED',
            'ENCODING_MODE_AEAD',
            'OPT_TLS_CA_FILE',
            'OPT_TLS_PEER_NAME',
        ];
    }

    public function testPureCacheOnlyConstantsAreAbsentFromPeclWhenExtensionLoaded(): void
    {
        if (!\extension_loaded('memcached')) {
            self::markTestSkipped('PECL memcached extension is not loaded');
        }

        $pecl = (new \ReflectionClass(\Memcached::class))->getConstants();
        $missingFromPecl = [];
        foreach ($this->pureCacheOnlyConstantNames() as $name) {
            if (\array_key_exists($name, $pecl)) {
                $missingFromPecl[] = $name;
            }
        }

        self::assertSame(
            [],
            $missingFromPecl,
            'These constants must remain PureCache-only (not collide with future PECL names unexpectedly): '
            .implode(', ', $missingFromPecl),
        );
    }

    public function testPureCacheOnlyConstantsExistOnMemcachedConstants(): void
    {
        $pure = (new \ReflectionClass(MemcachedConstants::class))->getConstants();
        foreach ($this->pureCacheOnlyConstantNames() as $name) {
            self::assertArrayHasKey($name, $pure, $name.' must be defined on MemcachedConstants');
            self::assertArrayHasKey($name, (new \ReflectionClass(MemcachedClient::class))->getConstants());
        }
    }

    public function testPureCacheOnlyOptionConstantsUseDedicatedNegativeRange(): void
    {
        $encodingMode = MemcachedConstants::OPT_ENCODING_MODE;
        $tlsCa = MemcachedConstants::OPT_TLS_CA_FILE;
        $tlsPeer = MemcachedConstants::OPT_TLS_PEER_NAME;
        self::assertLessThan(0, $encodingMode);
        self::assertLessThan(0, $tlsCa);
        self::assertLessThan(0, $tlsPeer);
        self::assertLessThan($encodingMode, $tlsCa);
        self::assertLessThan($tlsCa, $tlsPeer);
    }
}
