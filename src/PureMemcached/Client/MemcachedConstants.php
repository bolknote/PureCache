<?php

declare(strict_types=1);

namespace PureMemcached\Client;

/**
 * Base class holding constants only (same shape as PECL). Do not instantiate; use {@see MemcachedClient}.
 */
abstract class MemcachedConstants
{
    public const OPT_COMPRESSION = -1001;

    public const OPT_COMPRESSION_TYPE = -1004;

    public const OPT_PREFIX_KEY = -1002;

    public const OPT_SERIALIZER = -1003;

    public const OPT_HASH = 2;

    public const HASH_DEFAULT = 0;

    public const HASH_MD5 = 1;

    public const HASH_CRC = 2;

    public const HASH_FNV1_64 = 3;

    public const HASH_FNV1A_64 = 4;

    public const HASH_FNV1_32 = 5;

    public const HASH_FNV1A_32 = 6;

    public const HASH_HSIEH = 7;

    public const HASH_MURMUR = 8;

    public const OPT_DISTRIBUTION = 9;

    public const DISTRIBUTION_MODULA = 0;

    public const DISTRIBUTION_CONSISTENT = 1;

    public const DISTRIBUTION_VIRTUAL_BUCKET = 6;

    public const OPT_LIBKETAMA_COMPATIBLE = 16;

    public const OPT_LIBKETAMA_HASH = 17;

    public const OPT_TCP_KEEPALIVE = 32;

    public const OPT_BUFFER_WRITES = 10;

    public const OPT_BINARY_PROTOCOL = 18;

    public const OPT_NO_BLOCK = 0;

    public const OPT_TCP_NODELAY = 1;

    public const OPT_SOCKET_SEND_SIZE = 4;

    public const OPT_SOCKET_RECV_SIZE = 5;

    public const OPT_CONNECT_TIMEOUT = 14;

    public const OPT_RETRY_TIMEOUT = 15;

    public const OPT_SEND_TIMEOUT = 19;

    public const OPT_RECV_TIMEOUT = 20;

    public const OPT_POLL_TIMEOUT = 8;

    public const OPT_CACHE_LOOKUPS = 6;

    public const OPT_SERVER_FAILURE_LIMIT = 21;

    public const OPT_AUTO_EJECT_HOSTS = 28;

    public const OPT_HASH_WITH_PREFIX_KEY = 25;

    public const OPT_NOREPLY = 26;

    public const OPT_SORT_HOSTS = 12;

    public const OPT_VERIFY_KEY = 13;

    public const OPT_USE_UDP = 27;

    public const OPT_NUMBER_OF_REPLICAS = 29;

    public const OPT_RANDOMIZE_REPLICA_READ = 30;

    public const OPT_CORK = 31;

    public const OPT_REMOVE_FAILED_SERVERS = 35;

    public const OPT_DEAD_TIMEOUT = 36;

    public const OPT_SERVER_TIMEOUT_LIMIT = 37;

    public const OPT_MAX = 38;

    public const OPT_IO_BYTES_WATERMARK = 23;

    public const OPT_IO_KEY_PREFETCH = 24;

    public const OPT_IO_MSG_WATERMARK = 22;

    public const OPT_LOAD_FROM_FILE = 34;

    public const OPT_SUPPORT_CAS = 7;

    public const OPT_TCP_KEEPIDLE = 33;

    public const OPT_USER_DATA = 11;

    public const RES_SUCCESS = 0;

    public const RES_FAILURE = 1;

    public const RES_HOST_LOOKUP_FAILURE = 2;

    public const RES_UNKNOWN_READ_FAILURE = 7;

    public const RES_PROTOCOL_ERROR = 8;

    public const RES_CLIENT_ERROR = 9;

    public const RES_SERVER_ERROR = 10;

    public const RES_WRITE_FAILURE = 5;

    public const RES_DATA_EXISTS = 12;

    public const RES_NOTSTORED = 14;

    public const RES_NOTFOUND = 16;

    public const RES_PARTIAL_READ = 18;

    public const RES_SOME_ERRORS = 19;

    public const RES_NO_SERVERS = 20;

    public const RES_END = 21;

    public const RES_ERRNO = 26;

    public const RES_BUFFERED = 32;

    public const RES_TIMEOUT = 31;

    public const RES_BAD_KEY_PROVIDED = 33;

    public const RES_STORED = 15;

    public const RES_DELETED = 22;

    public const RES_STAT = 24;

    public const RES_ITEM = 25;

    public const RES_NOT_SUPPORTED = 28;

    public const RES_FETCH_NOTFINISHED = 30;

    public const RES_SERVER_MARKED_DEAD = 35;

    public const RES_UNKNOWN_STAT_KEY = 36;

    public const RES_INVALID_HOST_PROTOCOL = 34;

    public const RES_MEMORY_ALLOCATION_FAILURE = 17;

    public const RES_E2BIG = 37;

    public const RES_KEY_TOO_BIG = 39;

    public const RES_SERVER_TEMPORARILY_DISABLED = 47;

    public const RES_SERVER_MEMORY_ALLOCATION_FAILURE = 48;

    public const RES_AUTH_PROBLEM = 40;

    public const RES_AUTH_FAILURE = 41;

    public const RES_AUTH_CONTINUE = 42;

    public const RES_CONNECTION_FAILURE = 3;

    public const RES_CONNECTION_BIND_FAILURE = 4;

    public const RES_READ_FAILURE = 6;

    public const RES_DATA_DOES_NOT_EXIST = 13;

    public const RES_VALUE = 23;

    public const RES_FAIL_UNIX_SOCKET = 27;

    public const RES_NO_KEY_PROVIDED = 29;

    public const RES_INVALID_ARGUMENTS = 38;

    public const RES_PARSE_ERROR = 43;

    public const RES_PARSE_USER_ERROR = 44;

    public const RES_DEPRECATED = 45;

    public const RES_IN_PROGRESS = 46;

    public const RES_MAXIMUM_RETURN = 49;

    public const ON_CONNECT = 0;

    public const ON_ADD = 1;

    public const ON_APPEND = 2;

    public const ON_DECREMENT = 3;

    public const ON_DELETE = 4;

    public const ON_FLUSH = 5;

    public const ON_GET = 6;

    public const ON_INCREMENT = 7;

    public const ON_NOOP = 8;

    public const ON_PREPEND = 9;

    public const ON_QUIT = 10;

    public const ON_REPLACE = 11;

    public const ON_SET = 12;

    public const ON_STAT = 13;

    public const ON_VERSION = 14;

    public const RESPONSE_SUCCESS = 0;

    public const RESPONSE_KEY_ENOENT = 1;

    public const RESPONSE_KEY_EEXISTS = 2;

    public const RESPONSE_E2BIG = 3;

    public const RESPONSE_EINVAL = 4;

    public const RESPONSE_NOT_STORED = 5;

    public const RESPONSE_DELTA_BADVAL = 6;

    public const RESPONSE_NOT_MY_VBUCKET = 7;

    public const RESPONSE_AUTH_ERROR = 32;

    public const RESPONSE_AUTH_CONTINUE = 33;

    public const RESPONSE_UNKNOWN_COMMAND = 129;

    public const RESPONSE_ENOMEM = 130;

    public const RESPONSE_NOT_SUPPORTED = 131;

    public const RESPONSE_EINTERNAL = 132;

    public const RESPONSE_EBUSY = 133;

    public const RESPONSE_ETMPFAIL = 134;

    public const RES_CONNECTION_SOCKET_CREATE_FAILURE = 11;

    public const RES_PAYLOAD_FAILURE = -1001;

    public const SERIALIZER_PHP = 1;

    public const SERIALIZER_IGBINARY = 2;

    public const SERIALIZER_JSON = 3;

    public const SERIALIZER_JSON_ARRAY = 4;

    public const SERIALIZER_MSGPACK = 5;

    public const COMPRESSION_FASTLZ = 2;

    public const COMPRESSION_ZLIB = 1;

    public const COMPRESSION_ZSTD = 3;

    public const COMPRESSION_TYPE_FASTLZ = 2;

    public const COMPRESSION_TYPE_ZLIB = 1;

    public const COMPRESSION_TYPE_ZSTD = 3;

    public const GET_PRESERVE_ORDER = 1;

    public const GET_EXTENDED = 2;

    public const GET_ERROR_RETURN_VALUE = false;

    public const HAVE_IGBINARY = 0;

    public const HAVE_JSON = 1;

    public const HAVE_MSGPACK = 0;

    public const HAVE_ZSTD = 0;

    public const HAVE_ENCODING = 0;

    public const HAVE_SESSION = 0;

    public const HAVE_SASL = 0;

    public const OPT_COMPRESSION_LEVEL = -1007;

    public const OPT_STORE_RETRY_COUNT = -1005;

    public const OPT_USER_FLAGS = -1006;

    public const OPT_ITEM_SIZE_LIMIT = -1008;

    public const LIBMEMCACHED_VERSION_HEX = 0x0100010F;
}
