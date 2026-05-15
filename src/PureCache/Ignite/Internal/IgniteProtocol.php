<?php

declare(strict_types=1);

namespace PureCache\Ignite\Internal;

/**
 * Constants used by the Ignite thin client binary protocol.
 *
 * Only the subset of opcodes / type codes that the PureCache Ignite backend
 * actually emits is listed here so unused values do not silently bit-rot.
 *
 * Wire format reference: https://ignite.apache.org/docs/latest/binary-client-protocol/binary-client-protocol
 */
final class IgniteProtocol
{
    public const int DEFAULT_PORT = 10800;

    public const int HANDSHAKE_CODE = 1;

    public const int CLIENT_TYPE_THIN = 2;

    public const int HANDSHAKE_OK = 1;

    public const int RESPONSE_OK = 0;

    /** Mirrors {@code org.apache.ignite...ClientStatus}. */
    public const int STATUS_FAILED = 1;

    public const int STATUS_INVALID_NODE_STATE = 10;

    public const int STATUS_NODE_IN_RECOVERY_MODE = 11;

    public const int STATUS_CACHE_DOES_NOT_EXIST = 1000;

    public const int STATUS_RESOURCE_DOES_NOT_EXIST = 1011;

    public const int STATUS_SECURITY_VIOLATION = 1012;

    public const int STATUS_AUTH_FAILED = 2000;

    /** @var array<int, true> opcodes safe to resend once after transport reconnect */
    private const array TRANSPORT_RETRY_OPCODES = [
        self::OP_CACHE_GET => true,
        self::OP_CACHE_GET_ALL => true,
        self::OP_CACHE_GET_SIZE => true,
        self::OP_QUERY_SQL_FIELDS => true,
        self::OP_RESOURCE_CLOSE => true,
    ];

    public const int PROTOCOL_MAJOR = 1;

    public const int PROTOCOL_MINOR = 2;

    public const int PROTOCOL_PATCH = 0;

    public const int OP_RESOURCE_CLOSE = 0;

    public const int OP_CACHE_GET = 1000;

    public const int OP_CACHE_PUT = 1001;

    public const int OP_CACHE_PUT_IF_ABSENT = 1002;

    public const int OP_CACHE_GET_ALL = 1003;

    public const int OP_CACHE_REPLACE = 1009;

    public const int OP_CACHE_REPLACE_IF_EQUALS = 1010;

    public const int OP_CACHE_CONTAINS_KEY = 1011;

    public const int OP_CACHE_CLEAR = 1013;

    public const int OP_CACHE_REMOVE_KEY = 1016;

    public const int OP_CACHE_REMOVE_KEYS = 1018;

    public const int OP_CACHE_GET_SIZE = 1020;

    public const int OP_CACHE_GET_NAMES = 1050;

    public const int OP_CACHE_GET_OR_CREATE_WITH_NAME = 1052;

    public const int OP_QUERY_SCAN = 2000;

    public const int OP_QUERY_SCAN_CURSOR_GET_PAGE = 2001;

    public const int OP_QUERY_SQL_FIELDS = 2004;

    public const int OP_QUERY_SQL_FIELDS_CURSOR_GET_PAGE = 2005;

    public const int SQL_STATEMENT_SELECT = 1;

    /** Object type prefix bytes used in cache key/value payloads. */
    public const int TYPE_INT = 3;

    public const int TYPE_LONG = 4;

    public const int TYPE_STRING = 9;

    public const int TYPE_BYTE_ARRAY = 12;

    public const int TYPE_NULL = 101;

    public static function allowsTransportRetry(int $opCode): bool
    {
        return isset(self::TRANSPORT_RETRY_OPCODES[$opCode]);
    }
}
