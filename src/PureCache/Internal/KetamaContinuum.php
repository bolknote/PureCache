<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Ketama continuum compatible with libmemcached's update_continuum().
 */
final class KetamaContinuum
{
    private const int DEFAULT_PORT = 11211;

    private const int POINTS_PER_SERVER = 100;

    private const int POINTS_PER_SERVER_KETAMA = 160;

    /** @var list<array{h:int, i:int}> sorted by h */
    private array $ring = [];

    /**
     * @param list<array{0:string,1:int,2:int}> $servers host, port, weight (weight<=0 treated as 1)
     */
    public function __construct(array $servers, private readonly int $hashAlgorithm = MemcachedConstants::HASH_DEFAULT, private readonly bool $weightedKetama = false)
    {
        $liveServers = \count($servers);
        $totalWeight = 0;
        if ($this->weightedKetama) {
            foreach ($servers as [, , $weight]) {
                $totalWeight += $weight > 0 ? $weight : 1;
            }
        }

        foreach ($servers as $idx => $s) {
            [$host, $port, $weight] = $s;
            $pointsPerServer = self::POINTS_PER_SERVER;
            $pointsPerHash = 1;

            if ($this->weightedKetama) {
                $effectiveWeight = $weight > 0 ? $weight : 1;
                $pct = $totalWeight > 0 ? (float) $effectiveWeight / (float) $totalWeight : 0.0;
                $pointsPerServer = (int) (floor(
                    $pct * (float) self::POINTS_PER_SERVER_KETAMA / 4.0 * (float) $liveServers + 0.0000000001,
                ) * 4.0);
                $pointsPerHash = 4;
            }

            for ($pointerIndex = 0; $pointerIndex < intdiv($pointsPerServer, $pointsPerHash); ++$pointerIndex) {
                $label = $this->pointLabel($host, $port, $pointerIndex);
                if ($this->weightedKetama) {
                    for ($alignment = 0; $alignment < $pointsPerHash; ++$alignment) {
                        $this->ring[] = ['h' => $this->ketamaServerHash($label, $alignment), 'i' => $idx];
                    }
                } else {
                    $this->ring[] = ['h' => KeyHasher::hash($label, $this->hashAlgorithm), 'i' => $idx];
                }
            }
        }

        usort(
            $this->ring,
            static fn (array $a, array $b): int => $a['h'] === $b['h'] ? $a['i'] <=> $b['i'] : $a['h'] <=> $b['h'],
        );
    }

    /**
     * libmemcached omits the default port from Ketama labels.
     */
    private function pointLabel(string $host, int $port, int $point): string
    {
        if (self::DEFAULT_PORT === $port) {
            return $host.'-'.$point;
        }

        return $host.':'.$port.'-'.$point;
    }

    private function ketamaServerHash(string $label, int $alignment): int
    {
        $digest = md5($label, true);
        $offset = $alignment * 4;

        return \ord($digest[$offset])
            | (\ord($digest[$offset + 1]) << 8)
            | (\ord($digest[$offset + 2]) << 16)
            | (\ord($digest[$offset + 3]) << 24);
    }

    public function pick(int $keyHash): int
    {
        if ([] === $this->ring) {
            return 0;
        }

        $kh = $keyHash & 0xFFFFFFFF;
        $lo = 0;
        $hi = \count($this->ring) - 1;
        $answer = $this->ring[0]['i'];
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            $h = $this->ring[$mid]['h'];
            if ($h >= $kh) {
                $answer = $this->ring[$mid]['i'];
                $hi = $mid - 1;
            } else {
                $lo = $mid + 1;
            }
        }

        return $answer;
    }
}
