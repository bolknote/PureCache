<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\AbstractCacheClient;
use PureCache\MemcachedConstants;

/**
 * Shared {@see OptionEnvironment::applyCustomOption()} hooks for all backends.
 */
final class ClientCustomOptionHandler
{
    /**
     * @param AbstractCacheClient<ClientCoreState> $client
     */
    public static function apply(
        int $option,
        mixed $value,
        ClientCoreState $core,
        AbstractCacheClient $client,
    ): ?ClientOptionResult {
        if (MemcachedConstants::OPT_LOAD_FROM_FILE !== $option) {
            return null;
        }

        $path = ClientOptions::stringValue($value);
        if (null === $path) {
            return ClientOptionResult::failure(
                MemcachedConstants::RES_INVALID_ARGUMENTS,
                'OPT_LOAD_FROM_FILE expects a filename string',
            );
        }

        $result = LibmemcachedConfigFile::applyToClient($path, $client);
        if ($result->ok) {
            $core->options[MemcachedConstants::OPT_LOAD_FROM_FILE] = $path;
        }

        return $result;
    }
}
