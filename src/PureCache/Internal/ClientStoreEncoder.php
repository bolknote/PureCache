<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Encode values for store paths and enforce PECL-shaped size / append guards.
 */
final readonly class ClientStoreEncoder
{
    /**
     * @param \Closure(): (?EncodingContext) $encodingContext
     */
    public function __construct(
        private ClientCoordinatorEnv $env,
        private \Closure $encodingContext,
    ) {
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    public function encode(mixed $value): ?array
    {
        try {
            [$payload, $flags] = ValueCodec::encode(
                $value,
                $this->env->optionInt(MemcachedConstants::OPT_SERIALIZER, MemcachedConstants::SERIALIZER_PHP),
                $this->env->optionBool(MemcachedConstants::OPT_COMPRESSION, true),
                $this->env->optionInt(MemcachedConstants::OPT_COMPRESSION_TYPE, MemcachedConstants::COMPRESSION_TYPE_FASTLZ),
                $this->env->optionInt(MemcachedConstants::OPT_COMPRESSION_LEVEL, 3),
                $this->env->core->compressionThreshold,
                $this->env->core->compressionFactor,
                $this->env->optionInt(MemcachedConstants::OPT_USER_FLAGS, -1),
                ($this->encodingContext)(),
            );
        } catch (\Throwable) {
            $this->env->setResult(MemcachedConstants::RES_PAYLOAD_FAILURE);

            return null;
        }

        $limit = $this->env->optionInt(MemcachedConstants::OPT_ITEM_SIZE_LIMIT, 0);
        if ($limit > 0 && \strlen($payload) > $limit) {
            $this->env->setResult(MemcachedConstants::RES_E2BIG);

            return null;
        }

        return [$payload, $flags];
    }

    public function rejectIncompatibleConcatenation(StoreMode $mode): bool
    {
        if (!$mode->isConcatenation()) {
            return true;
        }

        if ($this->env->optionBool(MemcachedConstants::OPT_COMPRESSION, true)) {
            trigger_error('cannot append/prepend with compression turned on', \E_USER_WARNING);
            $this->env->setResult(MemcachedConstants::RES_NOTSTORED);

            return false;
        }

        if (($this->encodingContext)() instanceof EncodingContext) {
            trigger_error('cannot append/prepend with encoding key set', \E_USER_WARNING);
            $this->env->setResult(MemcachedConstants::RES_NOTSTORED);

            return false;
        }

        return true;
    }
}
