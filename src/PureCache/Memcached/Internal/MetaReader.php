<?php

declare(strict_types=1);

namespace PureCache\Memcached\Internal;

final readonly class MetaReader
{
    public function __construct(private StreamConnection $conn)
    {
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
            $parts = preg_split('/\s+/', trim($line), -1, \PREG_SPLIT_NO_EMPTY);
            if (false === $parts) {
                $parts = [];
            }

            $size = isset($parts[1]) ? (int) $parts[1] : 0;

            if ($size > 0) {
                $body = $this->conn->readExact($size);
                $this->conn->consumeCrLfAfterBody();

                return $expectValueBlock ? $r->withValue($body) : $r;
            }

            if (0 === $size) {
                $this->conn->consumeCrLfAfterBody();

                return $expectValueBlock ? $r->withValue('') : $r;
            }

            return $r;
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
            $parts = preg_split('/\s+/', trim($line), -1, \PREG_SPLIT_NO_EMPTY);
            if (false === $parts) {
                $parts = [];
            }

            $size = isset($parts[1]) ? (int) $parts[1] : 0;
            $body = $this->conn->readExact($size);
            $this->conn->consumeCrLfAfterBody();

            return $r->withValue($body);
        }

        return $r;
    }
}
