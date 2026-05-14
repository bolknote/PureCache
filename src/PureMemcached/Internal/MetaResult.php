<?php

declare(strict_types=1);

namespace PureMemcached\Internal;

use PureMemcached\Client\MemcachedConstants;

/**
 * One logical meta (or error) response from memcached.
 */
final readonly class MetaResult
{
    public function __construct(
        public string $code,
        /** @var array<string, string> meta tokens (first letter -> rest) */
        public array $tokens,
        public ?string $value,
        public ?string $errorMessage = null,
    ) {
    }

    public static function fromLine(string $line): self
    {
        $parts = [];
        if ('' !== $line) {
            $split = preg_split('/\s+/', trim($line), -1, \PREG_SPLIT_NO_EMPTY);
            if (false !== $split) {
                $parts = $split;
            }
        }

        if ([] === $parts) {
            return new self('', [], null, 'Empty response line');
        }

        $code = $parts[0];
        if (str_starts_with($code, 'CLIENT_ERROR') || str_starts_with($code, 'SERVER_ERROR') || 'ERROR' === $code) {
            $msg = trim(substr($line, \strlen($code)));

            return new self($code, [], null, '' !== $msg ? $msg : $line);
        }

        $tokens = [];
        $start = 1;
        if ('VA' === $code && isset($parts[1]) && is_numeric($parts[1])) {
            $start = 2;
        }

        $counter = \count($parts);
        for ($i = $start; $i < $counter; ++$i) {
            $t = $parts[$i];
            $flag = $t[0];
            $tokens[$flag] = substr($t, 1);
        }

        return new self($code, $tokens, null);
    }

    public function withValue(?string $value): self
    {
        return new self($this->code, $this->tokens, $value, $this->errorMessage);
    }

    public function getToken(string $flag, ?string $default = null): ?string
    {
        return $this->tokens[$flag] ?? $default;
    }

    public function getCas(): ?string
    {
        return $this->getToken('c');
    }

    public function getClientFlags(): ?int
    {
        $f = $this->getToken('f');

        return null !== $f && '' !== $f ? (int) $f : null;
    }

    /**
     * When the server sent CLIENT_ERROR / SERVER_ERROR / ERROR or the line is otherwise invalid,
     * returns the MemcachedClient result code to surface (PECL-compatible where constants exist).
     * Returns null for normal meta codes (HD, VA, NF, …).
     */
    public function wireErrorResultCode(): ?int
    {
        if (null === $this->errorMessage) {
            return null;
        }

        if (str_starts_with($this->code, 'SERVER_ERROR')) {
            return MemcachedConstants::RES_SERVER_ERROR;
        }

        if (str_starts_with($this->code, 'CLIENT_ERROR')) {
            return MemcachedConstants::RES_CLIENT_ERROR;
        }

        if ('ERROR' === $this->code) {
            return MemcachedConstants::RES_FAILURE;
        }

        return MemcachedConstants::RES_PROTOCOL_ERROR;
    }
}
