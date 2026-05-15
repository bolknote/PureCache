<?php

declare(strict_types=1);

namespace PureCache\Ignite\Internal;

/**
 * Lightweight snapshot of {@see \PureCache\Ignite\NativeIgniteClient} bookkeeping
 * counters: bytes pushed in either direction, per-opcode call counts, and the
 * unix epoch the handshake completed at.
 *
 * The thin client protocol does not expose JVM-level metrics, so this is the
 * richest local signal we can build into a memcached 1.6-shaped {@code stats}
 * map. {@see \PureCache\Ignite\IgniteStatsAsMemcached} consumes it.
 */
final readonly class IgniteStatsSnapshot
{
    /**
     * @param array<int, int> $opCounts opcode → wire attempts ({@see \PureCache\Ignite\NativeIgniteClient}), including transport retries
     */
    public function __construct(
        public string $serverVersion,
        public int $connectedAt,
        public int $bytesRead,
        public int $bytesWritten,
        public array $opCounts,
    ) {
    }

    /**
     * Returns the cumulative number of requests sent across all opcodes.
     */
    public function totalOps(): int
    {
        return array_sum($this->opCounts);
    }

    /**
     * Returns the sum of call counts for the supplied opcodes (0 if the
     * connection has never issued any of them).
     *
     * @param list<int> $opCodes
     */
    public function sumOpCounts(array $opCodes): int
    {
        $sum = 0;
        foreach ($opCodes as $op) {
            $sum += $this->opCounts[$op] ?? 0;
        }

        return $sum;
    }
}
