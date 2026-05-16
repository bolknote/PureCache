<?php

declare(strict_types=1);

namespace PureCache\Memcached\Internal;

use PureCache\Internal\ItemSizeGuard;

final readonly class MetaReader
{
    /** Meta result code surfaced when a {@code VA} body exceeds the read limit. */
    public const string CODE_ITEM_TOO_BIG = 'E2BIG';

    public function __construct(
        private StreamConnection $conn,
        private int $maxBodyBytes = ItemSizeGuard::ABSOLUTE_MAX_BYTES,
    ) {
    }

    /**
     * Read one meta response (and optional value block for VA).
     *
     * When {@code $expectValueBlock} is {@code false} but the server still
     * returned a {@code VA} line with a non-empty body, we MUST consume the
     * body + trailing CRLF anyway — otherwise the next read on this socket
     * would observe stale bytes from the previous value chunk and produce
     * silent data corruption. The unwanted body is discarded.
     */
    public function readOne(bool $expectValueBlock): MetaResult
    {
        $line = $this->conn->readLine();
        $r = MetaResult::fromLine($line);
        if (null !== $r->errorMessage) {
            return $r;
        }

        if ('VA' === $r->code) {
            return $this->readVaBlock($line, $r, $expectValueBlock);
        }

        return $r;
    }

    /**
     * Read meta arithmetic response: optional VA line + decimal + crlf, else HD/NF/...
     */
    public function readArithmeticValue(): MetaResult
    {
        $line = $this->conn->readLine();
        $r = MetaResult::fromLine($line);
        if (null !== $r->errorMessage) {
            return $r;
        }

        // Memcached uses "VA <size> …\r\n" + value chunk; Dragonfly may return only the new counter as a decimal line.
        if ('VA' !== $r->code && '' !== $r->code && (string) (int) $r->code === $r->code) {
            return new MetaResult('VA', [], $r->code);
        }

        if ('VA' === $r->code) {
            return $this->readVaBlock($line, $r, true);
        }

        return $r;
    }

    private function readVaBlock(string $line, MetaResult $r, bool $expectValueBlock): MetaResult
    {
        $parts = preg_split('/\s+/', trim($line), -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $parts) {
            $parts = [];
        }

        $size = isset($parts[1]) ? (int) $parts[1] : 0;
        $oversized = ItemSizeGuard::rejectOversizedDeclaredBody($size, $this->maxBodyBytes);

        if ($size > 0) {
            $body = $this->conn->readExact($size);
            $this->conn->consumeCrLfAfterBody();
            if ($oversized) {
                return $this->itemTooBigResult();
            }

            return $expectValueBlock ? $r->withValue($body) : $r;
        }

        if (0 === $size) {
            $this->conn->consumeCrLfAfterBody();

            return $expectValueBlock ? $r->withValue('') : $r;
        }

        return $r;
    }

    private function itemTooBigResult(): MetaResult
    {
        return new MetaResult(self::CODE_ITEM_TOO_BIG, [], null, 'ITEM TOO BIG');
    }
}
