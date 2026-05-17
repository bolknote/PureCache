<?php

declare(strict_types=1);

/**
 * Reproduces PeclParityTest::testLibketamaHashGetterTracksOptHashAcrossCascade.
 * Exit 0 on match with ext-memcached, 1 on mismatch (for CI/local debugging).
 */

require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/tests/bootstrap.php';

if (!extension_loaded('memcached')) {
    fwrite(\STDERR, "ext-memcached required\n");
    exit(2);
}

use PureCache\Internal\LibketamaHashOptionParity;
use PureCache\Memcached\MemcachedClient;

$pecl = new Memcached();
$pure = new MemcachedClient();

$surfaces = LibketamaHashOptionParity::setterSurfacesCoercedGetterWithoutCompat();
$setterUpdates = LibketamaHashOptionParity::setterUpdatesStoredKetamaGetter();
fwrite(\STDERR, 'probes: surfacesCoerced='.($surfaces ? 'y' : 'n').' setterUpdates='.($setterUpdates ? 'y' : 'n')."\n");

foreach ([Memcached::HASH_CRC, Memcached::HASH_MURMUR, Memcached::HASH_FNV1_64] as $hash) {
    $peclOk = $pecl->setOption(Memcached::OPT_HASH, $hash);
    $pureOk = $pure->setOption(MemcachedClient::OPT_HASH, $hash);
    $pl = $pecl->getOption(Memcached::OPT_LIBKETAMA_HASH);
    $pu = $pure->getOption(MemcachedClient::OPT_LIBKETAMA_HASH);
    $ph = $pure->getOption(MemcachedClient::OPT_HASH);

    $core = new ReflectionProperty(MemcachedClient::class, 'core');
    /** @var PureCache\Memcached\Internal\MemcachedClientCore $state */
    $state = $core->getValue($pure);

    fwrite(
        \STDERR,
        sprintf(
            "hash=%d peclOk=%s pureOk=%s pecl_lk=%s pure_lk=%s pure_hash=%s dialTouched=%s usesSlot=%s opt[HASH]=%s opt[LK]=%s\n",
            $hash,
            $peclOk ? '1' : '0',
            $pureOk ? '1' : '0',
            var_export($pl, true),
            var_export($pu, true),
            var_export($ph, true),
            $state->libketamaHashDialTouched ? 'y' : 'n',
            LibketamaHashOptionParity::libketamaGetterUsesStoredSlot($state) ? 'y' : 'n',
            var_export($state->options[MemcachedClient::OPT_HASH] ?? 'missing', true),
            var_export($state->options[MemcachedClient::OPT_LIBKETAMA_HASH] ?? 'missing', true),
        ),
    );

    if ($pl !== $pu) {
        fwrite(\STDERR, "MISMATCH at hash={$hash}\n");
        exit(1);
    }
}

fwrite(\STDERR, "OK\n");
exit(0);
