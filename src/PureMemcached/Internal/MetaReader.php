<?php

declare(strict_types=1);

namespace PureMemcached\Internal;

final readonly class MetaReader
{
    public function __construct(private StreamConnection $conn)
    {
    }

    /**
     * Read one meta response (and optional value block for VA).
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
            if ($expectValueBlock && $size > 0) {
                $body = $this->conn->readExact($size);
                $this->conn->consumeCrLfAfterBody();

                return $r->withValue($body);
            }

            if ($expectValueBlock && 0 === $size) {
                $this->conn->consumeCrLfAfterBody();

                return $r->withValue('');
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
        if ('VA' !== $r->code && '' !== $r->code && (string) (int) $r->code === (string) $r->code) {
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
