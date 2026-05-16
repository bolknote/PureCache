<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * PECL-shaped default {@code getResultMessage()} strings for {@code RES_*} codes.
 */
final class ClientResultCatalog
{
    private function __construct()
    {
    }

    public static function defaultMessage(int $code): string
    {
        return match ($code) {
            MemcachedConstants::RES_SUCCESS => 'SUCCESS',
            MemcachedConstants::RES_END => 'END',
            MemcachedConstants::RES_NOTFOUND => 'NOT FOUND',
            MemcachedConstants::RES_DATA_EXISTS => 'DATA EXISTS',
            MemcachedConstants::RES_NOTSTORED => 'NOT STORED',
            MemcachedConstants::RES_FAILURE => 'FAILURE',
            MemcachedConstants::RES_NO_SERVERS => 'NO SERVERS',
            MemcachedConstants::RES_BAD_KEY_PROVIDED => 'BAD KEY',
            MemcachedConstants::RES_PAYLOAD_FAILURE => 'PAYLOAD FAILURE',
            MemcachedConstants::RES_NOT_SUPPORTED => 'NOT SUPPORTED',
            MemcachedConstants::RES_INVALID_ARGUMENTS => 'INVALID ARGUMENTS',
            MemcachedConstants::RES_INVALID_HOST_PROTOCOL => 'INVALID HOST PROTOCOL',
            MemcachedConstants::RES_E2BIG => 'ITEM TOO BIG',
            MemcachedConstants::RES_FETCH_NOTFINISHED => 'FETCH NOT FINISHED',
            MemcachedConstants::RES_SOME_ERRORS => 'SOME ERRORS WERE REPORTED',
            MemcachedConstants::RES_WRITE_FAILURE => 'WRITE FAILURE',
            MemcachedConstants::RES_PARTIAL_READ => 'PARTIAL READ',
            MemcachedConstants::RES_BUFFERED => 'BUFFERED',
            MemcachedConstants::RES_SERVER_TEMPORARILY_DISABLED => 'SERVER TEMPORARILY DISABLED',
            MemcachedConstants::RES_SERVER_MEMORY_ALLOCATION_FAILURE => 'SERVER MEMORY ALLOCATION FAILURE',
            MemcachedConstants::RES_AUTH_PROBLEM => 'AUTH PROBLEM',
            MemcachedConstants::RES_AUTH_FAILURE => 'AUTH FAILURE',
            MemcachedConstants::RES_AUTH_CONTINUE => 'AUTH CONTINUE',
            default => 'UNKNOWN',
        };
    }
}
