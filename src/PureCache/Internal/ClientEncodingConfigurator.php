<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * {@code setEncodingKey()} validation and {@see EncodingContext} installation.
 */
final readonly class ClientEncodingConfigurator
{
    public function __construct(private ClientCoordinatorEnv $env)
    {
    }

    public function setEncodingKey(#[\SensitiveParameter] string $key): bool
    {
        if ('' === $key) {
            $this->env->setResult(MemcachedConstants::RES_INVALID_ARGUMENTS, 'encoding key must not be empty');

            return false;
        }

        if (!\extension_loaded('openssl')) {
            $this->env->setResult(MemcachedConstants::RES_NOT_SUPPORTED, 'encoding requires ext-openssl');

            return false;
        }

        $mode = $this->env->core->optionInt(MemcachedConstants::OPT_ENCODING_MODE, MemcachedConstants::ENCODING_MODE_LIBMEMCACHED);
        $ctx = EncodingContext::fromUserKey($mode, $key);
        if (!$ctx instanceof EncodingContext) {
            $this->env->setResult(MemcachedConstants::RES_INVALID_ARGUMENTS, 'invalid encoding mode');

            return false;
        }

        $this->env->core->encoding = $ctx;
        $this->env->setResult(MemcachedConstants::RES_SUCCESS);

        return true;
    }
}
