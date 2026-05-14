# purecache/cache

Pure PHP 8.3 implementation of the PECL `Memcached` API (memcached meta protocol and optional Redis backend). All memcached cache operations use the **memcached meta protocol** (`mg`, `ms`, `md`, `ma`, `me`, `mn`) on the TCP connection.

## Namespace (no dependency on ext-memcached)

All implementation code lives under **`PureCache`** and **does not rely on** the global `\Memcached` class or its constants—the PECL extension may or may not be installed.

- Memcached (meta protocol): `PureCache\Memcached\MemcachedClient`
- Redis-backed client: `PureCache\Redis\RedisClient`
- Multi-backend factory: `PureCache\ClientFactory` (see `PureCache\CacheClient`)
- Constants (`RES_*`, `OPT_*`, …): `PureCache\MemcachedConstants` (abstract base class). The same names are also available as `MemcachedClient::RES_*` because the client extends that class.

Example:

```php
use PureCache\Memcached\MemcachedClient;

$m = new MemcachedClient();
$m->addServer('127.0.0.1', 11211);
$m->set('k', 'v', 60);
if ($m->getResultCode() === MemcachedClient::RES_SUCCESS) { /* … */ }
```

## Auxiliary text commands

Memcached has no meta equivalents for server-wide operations used by these PECL methods:

- `getStats`, `getVersion`, `flush`, `getAllKeys`

Those methods send the same classic **text** one-liners on the same socket (after the connection is established). All `get` / `set` / `cas` / `increment` / `delete` / etc. use **meta only**.

## Requirements

- PHP 8.3+
- A memcached **1.6+** server for integration tests
- `ext-zlib` recommended for compression parity
- `ext-igbinary` optional for `SERIALIZER_IGBINARY` parity
- `ext-fastlz` optional for FastLZ wire compatibility

## Install

```bash
composer require purecache/cache
```

## Optional global shim (only when PECL is not loaded)

The `bootstrap-alias.php` file defines the global **`Memcached`** class as an alias of `PureCache\Memcached\MemcachedClient` **only when** the PECL extension is not loaded **and** the global class is not already defined. It is **not** loaded via Composer `autoload.files` — opt in from your bootstrap (the test suite loads it from `tests/bootstrap.php`).

```php
require __DIR__ . '/vendor/.../bootstrap-alias.php';
$m = new Memcached(); // only when PECL is not loaded; otherwise this is the extension class
```

Prefer importing constants from `PureCache\MemcachedConstants` in application code so you do not depend on whether the shim was loaded.

## Redis backend notes

- Item keys are stored under the `pm:v1:` prefix with a hash per logical memcached item (`HSET` fields `d`, `f`, `c`).
- Mutations that must be atomic (`set`/`cas`/`add`/`replace`/`append`/`prepend`/`incr`/`decr`/`touch`) are implemented with **Redis `EVAL` Lua** scripts so compare-and-swap and counter updates are not split across round-trips.
- `OPT_RECV_TIMEOUT` / `OPT_SEND_TIMEOUT` are interpreted as **milliseconds** (same as the memcached client) and applied as Redis read/write timeouts in **seconds** on the socket.

## Tests

```bash
composer test
```

`composer test` runs **unit tests only** (no memcached server required). If `ext-igbinary` is installed but not enabled in `php.ini`, the test runner still loads `igbinary` from PHP's `extension_dir` when the shared object is present so serializer unit tests do not skip unnecessarily.

To run tests against a real memcached server, use the wrapper: it starts memcached on a free local port, sets `MEMCACHED_TEST_*`, runs the integration suite, then stops the server:

```bash
composer test:integration
```

Set `MEMCACHED_BINARY=/path/to/memcached` if `memcached` is not on `PATH`.

When the PECL `memcached` extension is installed, run parity checks against the native extension:

```bash
composer test:parity
```

The parity runner starts a fresh memcached server and compares supported API behavior between `\Memcached` and `PureCache\Memcached\MemcachedClient`. If `memcached.so` is available in PHP's `extension_dir`, it is loaded via `-d`; if `igbinary.so` is also available, it is loaded first so `SERIALIZER_IGBINARY` parity is covered.

## Compatibility matrix

The library is intentionally PECL-shaped, but it is not a libmemcached binding. Unsupported behavior is rejected explicitly instead of being silently stored as a no-op option.

### Supported data operations

- Immediate key/value operations: `get`, `getByKey`, `getMulti`, `getMultiByKey`, `set`, `setByKey`, `setMulti`, `setMultiByKey`, `add`, `replace`, `append`, `prepend`, `cas`, `touch`, `delete`, `deleteMulti`, `increment`, `decrement`, and their `ByKey` variants.
- `setMulti()` and `setMultiByKey()` use batched meta `ms` writes grouped by server, then read replies in order. Local per-item failures such as `OPT_ITEM_SIZE_LIMIT` do not block other items; the final result code becomes `RES_SOME_ERRORS`.
- Delayed fetch APIs are supported in-process: `getDelayed`, `getDelayedByKey`, `fetch`, and `fetchAll`.
- `NOREPLY` and buffered writes are supported for mutation commands where the meta protocol supports quiet writes.

### Server-wide operations

- `getStats`, `getVersion`, `flush`, and `getAllKeys` use classic text commands because memcached has no meta equivalent for those PECL methods.
- `getStats()` and `getVersion()` report `RES_SOME_ERRORS` when some servers fail but at least one response shape can still be returned.
- `getAllKeys()` is still best-effort: it runs `version` first, then prefers `lru_crawler metadump all` when the reported version is **≥ 1.5.6** (older releases are skipped because metadump streams were not reliably terminated). If the version string cannot be parsed, metadump is tried once and may fall back. On refusal or errors such as `BUSY`, `ERROR metadump not allowed`, LRU crawler disabled, or older servers, it falls back to `stats items` / `stats cachedump`. Metadump lines use LF terminators on the wire; the client handles that alongside normal CRLF text replies. Per-server failures surface as `RES_SOME_ERRORS`; total failure returns `false`.

### Supported options

- Keying and routing: `OPT_PREFIX_KEY`, `OPT_HASH`, `OPT_DISTRIBUTION`, `OPT_LIBKETAMA_COMPATIBLE`, `OPT_HASH_WITH_PREFIX_KEY`, `setBucket`.
- Value encoding: `OPT_SERIALIZER`, `OPT_COMPRESSION`, `OPT_COMPRESSION_TYPE`, `OPT_COMPRESSION_LEVEL`, `OPT_USER_FLAGS`, `OPT_ITEM_SIZE_LIMIT`.
- I/O behavior implemented by this client: `OPT_CONNECT_TIMEOUT`, `OPT_RECV_TIMEOUT`, `OPT_SEND_TIMEOUT`, `OPT_NOREPLY`, `OPT_BUFFER_WRITES`, `OPT_VERIFY_KEY`, `OPT_TCP_NODELAY`, `OPT_TCP_KEEPALIVE`, `OPT_SOCKET_SEND_SIZE`, `OPT_SOCKET_RECV_SIZE`.
- `OPT_NO_BLOCK` is accepted and reported like PECL for configuration compatibility, but operations still use blocking PHP streams with configured timeouts rather than libmemcached's non-blocking state machine.
- Local application storage: `OPT_USER_DATA`.

### Explicitly unsupported

- Protocol/network modes not implemented by the pure PHP meta client: `OPT_BINARY_PROTOCOL`, `OPT_USE_UDP`.
- Libmemcached failover/tuning options that require native client internals: `OPT_SORT_HOSTS`, `OPT_REMOVE_FAILED_SERVERS`, `OPT_RANDOMIZE_REPLICA_READ`, `OPT_CORK`, retry/dead-server limits, IO watermarks/prefetch, and replica read options.
- File/native-extension integration options: `OPT_LOAD_FROM_FILE`, `OPT_SUPPORT_CAS`, `OPT_TCP_KEEPIDLE`, `OPT_LIBKETAMA_HASH`.
- Authentication/encryption: `setSaslAuthData()` and `setEncodingKey()` return `RES_NOT_SUPPORTED`.
- `delete($key, $time)` with a positive delayed-delete time returns `RES_NOT_SUPPORTED`; the meta protocol delete path only supports immediate deletion.

### Compression notes

- `COMPRESSION_FASTLZ` requires a compatible `fastlz_*` extension. If it is unavailable, values are stored uncompressed instead of using a non-compatible stand-in.
- `COMPRESSION_ZLIB` works when `ext-zlib` is available.
- `COMPRESSION_ZSTD` requires a compatible Zstandard extension.
