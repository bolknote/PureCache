<?php

declare(strict_types=1);

namespace PureCache;

/**
 * Base class holding constants only (same shape as PECL). Do not instantiate; use {@see MemcachedClient}.
 */
abstract class MemcachedConstants
{
    public const int OPT_COMPRESSION = -1001;

    public const int OPT_COMPRESSION_TYPE = -1004;

    public const int OPT_PREFIX_KEY = -1002;

    public const int OPT_SERIALIZER = -1003;

    public const int OPT_HASH = 2;

    public const int HASH_DEFAULT = 0;

    public const int HASH_MD5 = 1;

    public const int HASH_CRC = 2;

    public const int HASH_FNV1_64 = 3;

    public const int HASH_FNV1A_64 = 4;

    public const int HASH_FNV1_32 = 5;

    public const int HASH_FNV1A_32 = 6;

    public const int HASH_HSIEH = 7;

    public const int HASH_MURMUR = 8;

    public const int OPT_DISTRIBUTION = 9;

    public const int DISTRIBUTION_MODULA = 0;

    public const int DISTRIBUTION_CONSISTENT = 1;

    public const int DISTRIBUTION_VIRTUAL_BUCKET = 6;

    public const int OPT_LIBKETAMA_COMPATIBLE = 16;

    public const int OPT_LIBKETAMA_HASH = 17;

    public const int OPT_TCP_KEEPALIVE = 32;

    public const int OPT_BUFFER_WRITES = 10;

    public const int OPT_BINARY_PROTOCOL = 18;

    public const int OPT_NO_BLOCK = 0;

    public const int OPT_TCP_NODELAY = 1;

    public const int OPT_SOCKET_SEND_SIZE = 4;

    public const int OPT_SOCKET_RECV_SIZE = 5;

    public const int OPT_CONNECT_TIMEOUT = 14;

    public const int OPT_RETRY_TIMEOUT = 15;

    public const int OPT_SEND_TIMEOUT = 19;

    public const int OPT_RECV_TIMEOUT = 20;

    public const int OPT_POLL_TIMEOUT = 8;

    public const int OPT_CACHE_LOOKUPS = 6;

    public const int OPT_SERVER_FAILURE_LIMIT = 21;

    public const int OPT_AUTO_EJECT_HOSTS = 28;

    public const int OPT_HASH_WITH_PREFIX_KEY = 25;

    public const int OPT_NOREPLY = 26;

    public const int OPT_SORT_HOSTS = 12;

    public const int OPT_VERIFY_KEY = 13;

    public const int OPT_USE_UDP = 27;

    public const int OPT_NUMBER_OF_REPLICAS = 29;

    public const int OPT_RANDOMIZE_REPLICA_READ = 30;

    public const int OPT_CORK = 31;

    public const int OPT_REMOVE_FAILED_SERVERS = 35;

    public const int OPT_DEAD_TIMEOUT = 36;

    public const int OPT_SERVER_TIMEOUT_LIMIT = 37;

    public const int OPT_MAX = 38;

    public const int OPT_IO_BYTES_WATERMARK = 23;

    public const int OPT_IO_KEY_PREFETCH = 24;

    public const int OPT_IO_MSG_WATERMARK = 22;

    public const int OPT_LOAD_FROM_FILE = 34;

    public const int OPT_SUPPORT_CAS = 7;

    public const int OPT_TCP_KEEPIDLE = 33;

    public const int OPT_USER_DATA = 11;

    public const int RES_SUCCESS = 0;

    public const int RES_FAILURE = 1;

    public const int RES_HOST_LOOKUP_FAILURE = 2;

    public const int RES_UNKNOWN_READ_FAILURE = 7;

    public const int RES_PROTOCOL_ERROR = 8;

    public const int RES_CLIENT_ERROR = 9;

    public const int RES_SERVER_ERROR = 10;

    public const int RES_WRITE_FAILURE = 5;

    public const int RES_DATA_EXISTS = 12;

    public const int RES_NOTSTORED = 14;

    public const int RES_NOTFOUND = 16;

    public const int RES_PARTIAL_READ = 18;

    public const int RES_SOME_ERRORS = 19;

    public const int RES_NO_SERVERS = 20;

    public const int RES_END = 21;

    public const int RES_ERRNO = 26;

    public const int RES_BUFFERED = 32;

    public const int RES_TIMEOUT = 31;

    public const int RES_BAD_KEY_PROVIDED = 33;

    public const int RES_STORED = 15;

    public const int RES_DELETED = 22;

    public const int RES_STAT = 24;

    public const int RES_ITEM = 25;

    public const int RES_NOT_SUPPORTED = 28;

    public const int RES_FETCH_NOTFINISHED = 30;

    public const int RES_SERVER_MARKED_DEAD = 35;

    public const int RES_UNKNOWN_STAT_KEY = 36;

    public const int RES_INVALID_HOST_PROTOCOL = 34;

    public const int RES_MEMORY_ALLOCATION_FAILURE = 17;

    public const int RES_E2BIG = 37;

    public const int RES_KEY_TOO_BIG = 39;

    public const int RES_SERVER_TEMPORARILY_DISABLED = 47;

    public const int RES_SERVER_MEMORY_ALLOCATION_FAILURE = 48;

    public const int RES_AUTH_PROBLEM = 40;

    public const int RES_AUTH_FAILURE = 41;

    public const int RES_AUTH_CONTINUE = 42;

    public const int RES_CONNECTION_FAILURE = 3;

    public const int RES_CONNECTION_BIND_FAILURE = 4;

    public const int RES_READ_FAILURE = 6;

    public const int RES_DATA_DOES_NOT_EXIST = 13;

    public const int RES_VALUE = 23;

    public const int RES_FAIL_UNIX_SOCKET = 27;

    public const int RES_NO_KEY_PROVIDED = 29;

    public const int RES_INVALID_ARGUMENTS = 38;

    public const int RES_PARSE_ERROR = 43;

    public const int RES_PARSE_USER_ERROR = 44;

    public const int RES_DEPRECATED = 45;

    public const int RES_IN_PROGRESS = 46;

    public const int RES_MAXIMUM_RETURN = 49;

    public const int ON_CONNECT = 0;

    public const int ON_ADD = 1;

    public const int ON_APPEND = 2;

    public const int ON_DECREMENT = 3;

    public const int ON_DELETE = 4;

    public const int ON_FLUSH = 5;

    public const int ON_GET = 6;

    public const int ON_INCREMENT = 7;

    public const int ON_NOOP = 8;

    public const int ON_PREPEND = 9;

    public const int ON_QUIT = 10;

    public const int ON_REPLACE = 11;

    public const int ON_SET = 12;

    public const int ON_STAT = 13;

    public const int ON_VERSION = 14;

    public const int RESPONSE_SUCCESS = 0;

    public const int RESPONSE_KEY_ENOENT = 1;

    public const int RESPONSE_KEY_EEXISTS = 2;

    public const int RESPONSE_E2BIG = 3;

    public const int RESPONSE_EINVAL = 4;

    public const int RESPONSE_NOT_STORED = 5;

    public const int RESPONSE_DELTA_BADVAL = 6;

    public const int RESPONSE_NOT_MY_VBUCKET = 7;

    public const int RESPONSE_AUTH_ERROR = 32;

    public const int RESPONSE_AUTH_CONTINUE = 33;

    public const int RESPONSE_UNKNOWN_COMMAND = 129;

    public const int RESPONSE_ENOMEM = 130;

    public const int RESPONSE_NOT_SUPPORTED = 131;

    public const int RESPONSE_EINTERNAL = 132;

    public const int RESPONSE_EBUSY = 133;

    public const int RESPONSE_ETMPFAIL = 134;

    public const int RES_CONNECTION_SOCKET_CREATE_FAILURE = 11;

    public const int RES_PAYLOAD_FAILURE = -1001;

    public const int SERIALIZER_PHP = 1;

    public const int SERIALIZER_IGBINARY = 2;

    public const int SERIALIZER_JSON = 3;

    public const int SERIALIZER_JSON_ARRAY = 4;

    public const int SERIALIZER_MSGPACK = 5;

    public const int COMPRESSION_FASTLZ = 2;

    public const int COMPRESSION_ZLIB = 1;

    public const int COMPRESSION_ZSTD = 3;

    public const int COMPRESSION_TYPE_FASTLZ = 2;

    public const int COMPRESSION_TYPE_ZLIB = 1;

    public const int COMPRESSION_TYPE_ZSTD = 3;

    public const int GET_PRESERVE_ORDER = 1;

    public const int GET_EXTENDED = 2;

    public const false GET_ERROR_RETURN_VALUE = false;

    public const int HAVE_IGBINARY = 0;

    public const int HAVE_JSON = 1;

    public const int HAVE_MSGPACK = 0;

    public const int HAVE_ZSTD = 0;

    public const int HAVE_ENCODING = 0;

    public const int HAVE_SESSION = 0;

    public const int HAVE_SASL = 0;

    public const int OPT_COMPRESSION_LEVEL = -1007;

    public const int OPT_STORE_RETRY_COUNT = -1005;

    public const int OPT_USER_FLAGS = -1006;

    public const int OPT_ITEM_SIZE_LIMIT = -1008;

    /**
     * PureCache extension (no PECL counterpart): allow PHP-serialized cache
     * payloads to rehydrate as their original class instances on decode. When
     * {@code false} (default) the deserializer hands back PHP's
     * {@code __PHP_Incomplete_Class} stub, which is safe against
     * code-execution gadgets baked into compromised cache servers. Set to
     * {@code true} only when full PECL parity is required and the cache is
     * trusted.
     */
    public const int OPT_ALLOW_SERIALIZED_CLASSES = -1009;

    public const int LIBMEMCACHED_VERSION_HEX = 0x0100010F;
}
