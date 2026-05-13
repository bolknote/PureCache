<?php

declare(strict_types=1);

namespace PureMemcached\Internal;

/**
 * Decoded value-level result for memcached meta get responses.
 */
final readonly class DecodedMetaValue
{
    private function __construct(
        public bool $found,
        public ?MetaResult $result,
        public mixed $value,
        public ?int $errorCode,
        public ?string $errorMessage,
    ) {
    }

    public static function found(MetaResult $result, mixed $value): self
    {
        return new self(true, $result, $value, null, null);
    }

    public static function missing(MetaResult $result): self
    {
        return new self(false, $result, null, null, null);
    }

    public static function failure(int $errorCode, ?string $errorMessage = null): self
    {
        return new self(false, null, null, $errorCode, $errorMessage);
    }

    public function isFailure(): bool
    {
        return null !== $this->errorCode;
    }

    public function result(): MetaResult
    {
        if (!$this->result instanceof MetaResult) {
            throw new \LogicException('Failed decoded meta value has no protocol result');
        }

        return $this->result;
    }
}
