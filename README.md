# purecache/cache

Pure PHP 8.3 implementation of the PECL `Memcached` API on top of pluggable backends: the memcached meta protocol, Redis (RESP2), and Apache Ignite (thin client binary protocol). All memcached cache operations use the **memcached meta protocol** (`mg`, `ms`, `md`, `ma`, `me`, `mn`) on the TCP connection.

## Namespace (no dependency on ext-memcached)

All implementation code lives under **`PureCache`** and **does not rely on** the global `\Memcached` class or its constants—the PECL extension may or may not be installed.

- Memcached (meta protocol): `PureCache\Memcached\MemcachedClient`
- Redis-backed client: `PureCache\Redis\RedisClient`
- Apache Ignite-backed client (native thin client): `PureCache\Ignite\IgniteClient`
- Multi-backend factory: `PureCache\ClientFactory` (see `PureCache\CacheClient`)
- Constants (`RES_*`, `OPT_*`, `SERIALIZER_*`, `COMPRESSION_*`, `HASH_*`, `DISTRIBUTION_*`, `GET_*`, `HAVE_*`, …) live on `PureCache\MemcachedConstants` and are inherited by **every** client class. Use whichever form reads best — `MemcachedClient::OPT_PREFIX_KEY`, `RedisClient::OPT_PREFIX_KEY`, `IgniteClient::OPT_PREFIX_KEY`, and `MemcachedConstants::OPT_PREFIX_KEY` all refer to the same value. Numeric values are pinned to PECL and verified in CI (`PeclParityTest::testEveryPeclConstantMatchesPureCache`).

All three clients share the same PECL-shaped API — only the class and transport differ.

### Memcached (meta protocol)

```php
use PureCache\Memcached\MemcachedClient;

$m = new MemcachedClient();
$m->addServer('127.0.0.1', 11211);

// Every PECL Memcached::* constant is inherited by the client, so
// MemcachedClient::OPT_* / SERIALIZER_* / RES_* drop straight into code
// that used to reference the global \Memcached class.
$m->setOption(MemcachedClient::OPT_PREFIX_KEY, 'app:');
$m->setOption(MemcachedClient::OPT_COMPRESSION, true);
$m->setOption(MemcachedClient::OPT_COMPRESSION_TYPE, MemcachedClient::COMPRESSION_ZSTD);
$m->setOption(MemcachedClient::OPT_SERIALIZER, MemcachedClient::SERIALIZER_IGBINARY);
$m->setOption(MemcachedClient::OPT_LIBKETAMA_COMPATIBLE, true);

$m->set('k', ['v' => 1], 60);

$value = $m->get('k', null, $cas);          // $cas is populated by reference
$m->cas($cas, 'k', ['v' => 2]);

if ($m->getResultCode() === MemcachedClient::RES_NOTFOUND) { /* … */ }
```

### Redis (RESP2 + Lua for atomic ops)

```php
use PureCache\Redis\RedisClient;
use PureCache\MemcachedConstants;

$r = new RedisClient();
// Plain host/port:
$r->addServer('127.0.0.1', 6379);
// Or via a URL with auth, TLS-style scheme, and database index:
$r->addServer('rediss://user:secret@cache.example.com:6380/2');

$r->setOption(MemcachedConstants::OPT_PREFIX_KEY, 'app:');
$r->set('session:42', ['logged_in' => true], 3600);

// CAS works the same as on memcached — handled atomically via EVAL.
$value = $r->get('counter', null, $cas);
$r->cas($cas, 'counter', $value + 1);

// increment_with_initial parity: missing key is seeded atomically.
$r->increment('hits', 1, 0, 86_400);
```

### Apache Ignite (thin client binary protocol)

```php
use PureCache\Ignite\IgniteClient;
use PureCache\MemcachedConstants;

$i = new IgniteClient();
$i->addServer('127.0.0.1', 10800);
// Multiple endpoints are treated as independent shards (same as the
// Redis backend); for a real Ignite cluster register one endpoint
// and let the server-side partitioner handle distribution.

$i->setOption(MemcachedConstants::OPT_PREFIX_KEY, 'pc:');
$i->setMulti(['a' => 1, 'b' => 2, 'c' => 3]);

$values = $i->getMulti(['a', 'b', 'c'], null, MemcachedConstants::GET_EXTENDED);
// → ['a' => ['value' => 1, 'cas' => …, 'flags' => 0], …]
```

### Backend-agnostic factory

`PureCache\ClientFactory::create()` picks an implementation by name and
optionally hands it a connection string. The same factory accepts
custom drivers registered through `ClientFactory::register()`.

```php
use PureCache\ClientFactory;
use PureCache\CacheClient;

// backend, $persistentId, $callback, $connection_str
$client = ClientFactory::create('redis', null, null, 'redis://127.0.0.1:6379/0');
// 'memcached' / 'mc'   → MemcachedClient
// 'redis'              → RedisClient
// 'ignite'  / 'ig'     → IgniteClient
// null or ''           → MemcachedClient (default)

assert($client instanceof CacheClient); // unified interface
$client->set('k', 'v', 60);
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

## `php.ini` directives

The memcached backend reads every `memcached.*` `php.ini` directive that the PECL extension registers in `php_memcached.c`, with the same defaults and the same `OnUpdate*` validators. Reading happens at `MemcachedClient` construction time (PECL parity — `MEMC_G(...)` is sampled once per `new Memcached()` call), so `ini_set('memcached.serializer', 'json')` followed by `new MemcachedClient()` produces an instance whose `OPT_SERIALIZER` is `SERIALIZER_JSON`.

Per-instance directives (read on every `new MemcachedClient()`):

| Directive | Default | Mapped to | Notes |
| --- | --- | --- | --- |
| `memcached.serializer` | `igbinary` → `msgpack` → `php` | `OPT_SERIALIZER` | Invalid values trigger `E_USER_WARNING` and fall back to the runtime default (same as PECL). |
| `memcached.compression_type` | `fastlz` | `OPT_COMPRESSION_TYPE` | Accepted: `fastlz`, `zlib`, `zstd`. Anything else warns and falls back to `fastlz`. |
| `memcached.compression_level` | `3` | `OPT_COMPRESSION_LEVEL` | |
| `memcached.compression_threshold` | `2000` | `ClientCoreState::$compressionThreshold` (not exposed via `setOption()` in PECL either) | Values smaller than the threshold are never compressed even when `OPT_COMPRESSION = true`. |
| `memcached.compression_factor` | `1.3` | `ClientCoreState::$compressionFactor` (INI-only in PECL) | Compressed payload is only kept when `plain_len > compressed_len * factor`. |
| `memcached.store_retry_count` | `0` | `OPT_STORE_RETRY_COUNT` | Stored on the client; the pure-PHP store path does not implement automatic fail-over yet. |
| `memcached.item_size_limit` | `0` | `OPT_ITEM_SIZE_LIMIT` | Pre-network check that mirrors PECL's `RES_E2BIG`. |
| `memcached.default_consistent_hash` | `Off` | `OPT_DISTRIBUTION = DISTRIBUTION_CONSISTENT` when On | Applied via the same code path PECL uses (`memcached_behavior_set(DISTRIBUTION_CONSISTENT)`). |
| `memcached.default_binary_protocol` | `Off` | `OPT_BINARY_PROTOCOL` (write-through), warning emitted when On | Pure-PHP transport speaks the meta protocol only; the warning matches PECL's `failed to set memcached behavior` shape. |
| `memcached.default_connect_timeout` | `0` | `OPT_CONNECT_TIMEOUT` | Applied only when non-zero, mirroring PECL. |

Session directives (read by `PureCache\Memcached\Session\MemcachedSessionHandler::open()`):

| Directive | Default | Notes |
| --- | --- | --- |
| `memcached.sess_locking` | `On` | When enabled, `read()` acquires `lock.{sid}` via `add` with exponential backoff. |
| `memcached.sess_lock_wait_min` / `memcached.sess_lock_wait_max` | `150` ms / `150` ms | Backoff starts at `wait_min`, doubles up to `wait_max` between retries. |
| `memcached.sess_lock_retries` | `5` | Initial attempt + N retries before giving up. |
| `memcached.sess_lock_expire` | `0` (falls back to `max_execution_time`) | TTL of the lock entry. |
| `memcached.sess_binary_protocol` | `On` | Recognised and validated, but the wire stays meta. One-shot `E_USER_WARNING` per process when On (matches PECL's "failed to set behavior" diagnostic). Old alias `memcached.sess_binary` is honored when the new key is unset. |
| `memcached.sess_consistent_hash` | `On` | Toggles `OPT_DISTRIBUTION = DISTRIBUTION_CONSISTENT` + `OPT_LIBKETAMA_COMPATIBLE`. |
| `memcached.sess_consistent_hash_type` | `ketama` | Accepts `ketama` or `ketama_weighted`; anything else warns and falls back to `ketama`. |
| `memcached.sess_number_of_replicas` | `0` | Used for the write-retry formula even though the meta transport has no native replica support. |
| `memcached.sess_randomize_replica_read` | `Off` | Set on the client for `getOption()` introspection; the meta transport does not honor it. |
| `memcached.sess_remove_failed_servers` | `Off` | When On, `write()` retries `1 + replicas * (failure_limit + 1)` times — same formula PECL uses. |
| `memcached.sess_server_failure_limit` | `0` | Feeds the write-retry formula above. |
| `memcached.sess_connect_timeout` | `0` ms | Applied via `OPT_CONNECT_TIMEOUT` when non-zero. |
| `memcached.sess_sasl_username` / `memcached.sess_sasl_password` | empty | Setting either causes `open()` to fail with `failed to set memcached session sasl credentials` — same wording PECL emits when `memcached_set_sasl_auth_data` returns `MEMCACHED_FAILURE`. The pure-PHP transport has no binary handshake, so SASL credentials cannot be honored. |
| `memcached.sess_persistent` | `Off` | When On, the underlying `MemcachedClient` is cached per `session.save_path` and reused on subsequent `open()` calls in the same process. |
| `memcached.sess_prefix` | `memc.sess.key.` | Validated to be ≤ 218 bytes (PECL's `MEMCACHED_MAX_NS_LEN - 1`); applied via `OPT_PREFIX_KEY`. |
| `memcached.sess_lock_wait` / `memcached.sess_lock_max_wait` | unset | Deprecated aliases; setting either triggers `E_USER_DEPRECATED` (same as PECL). |

`memcached.use_sasl` is not implemented — PECL itself removed it in 3.0.

## Session save handler

`PureCache\Memcached\Session\MemcachedSessionHandler` is a userland clone of PECL's `memcached` session handler. Because PHP cannot route `session.save_handler = memcached` directly to a userland class, register the handler explicitly:

```php
use PureCache\Memcached\Session\MemcachedSessionHandler;

ini_set('session.save_handler', 'user');
ini_set('session.save_path', '127.0.0.1:11211');

session_set_save_handler(new MemcachedSessionHandler(), true);
session_start();
```

The handler implements `SessionHandlerInterface`, `SessionIdInterface`, and `SessionUpdateTimestampHandlerInterface`. It reproduces the PECL state machine 1:1:

- `read()` acquires `lock.{sid}` via `add` with exponential backoff (`memcached.sess_lock_wait_min` → `lock_wait_max`, capped at `lock_retries` retries) before fetching the payload.
- `write()` retries the underlying `set()` `1 + replicas * (failure_limit + 1)` times when `memcached.sess_remove_failed_servers` is enabled, otherwise once.
- `destroy()` deletes the session key, then releases the lock with `delete(lock.{sid})`.
- `read()` maps `RES_NOTFOUND` to an empty string the same way PECL's `PS_READ_FUNC` does.
- TTLs use `session.gc_maxlifetime` (and `max_execution_time` for the lock if `memcached.sess_lock_expire = 0`), with the same ≤ 30-day relative / > 30-day absolute Unix-timestamp split memcached itself uses.

If you want to supply your own backend (different host, custom `OPT_*` configuration, mocking in tests), pass any `PureCache\CacheClient` to the constructor: `new MemcachedSessionHandler($myClient)`. When no client is injected, the handler creates one from `session.save_path`.

`memcached.sess_binary_protocol = On` and `memcached.sess_sasl_*` are recognised but cannot be honored — the pure-PHP transport speaks the meta protocol only and has no binary handshake for SASL. The handler emits a one-shot `E_USER_WARNING` for the binary case and refuses to `open()` when SASL credentials are configured, matching PECL's diagnostic wording.

## Redis backend notes

- Item keys are stored under the `pm:v1:` prefix with a hash per logical memcached item (`HSET` fields `d`, `f`, `c`).
- Mutations that must be atomic (`set`/`cas`/`add`/`replace`/`append`/`prepend`/`incr`/`decr`/`touch`) are implemented with **Redis `EVAL` Lua** scripts so compare-and-swap and counter updates are not split across round-trips.
- `setMulti()` / `setMultiByKey()` are pipelined per server — the client issues a single batch of `EVALSHA` / `EVAL` commands and reads all replies in order, mirroring how the memcached backend batches `ms` writes.
- `OPT_RECV_TIMEOUT` / `OPT_SEND_TIMEOUT` are interpreted as **milliseconds** (same as the memcached client) and applied as Redis read/write timeouts in **seconds** on the socket.
- TTLs respect the memcached convention: values up to `60 * 60 * 24 * 30` (30 days) are relative seconds; anything larger is treated as an absolute Unix timestamp and converted to a relative TTL on the wire.
- `increment()` / `decrement()` accept an `initial_value` and `expiry`. When the key is missing, the Lua script atomically seeds it with the initial counter value (typed as a long) and applies the expiry, matching PECL's `increment_with_initial` semantics.
- Authentication is configured through the connection string or `addServer()`. Pass `redis://user:password@host:port/db` (or `rediss://…` for TLS-style URLs); host/port-only entries continue to work. Username and password are sent via `AUTH`, and an optional database index is selected with `SELECT` during the handshake.

## Apache Ignite backend notes

- Speaks the Apache Ignite **thin-client binary protocol v1.2.0** over plain TCP (default port 10800). No third-party Ignite PHP package is required.
- All entries live in a single Ignite cache (`PURECACHE_V1`), automatically created on first use via `OP_CACHE_GET_OR_CREATE_WITH_NAME`. Each entry is a `byte[]` value with a 16-byte header carrying CAS, F-flags, and payload length, followed by the same payload bytes the memcached/Redis backends store.
- CAS is a 63-bit positive token kept inside the value header — it is **not** the Ignite entry version. `cas($token, …)` reads the wrapper, compares the header, and commits the new wrapper atomically through `OP_CACHE_REPLACE_IF_EQUALS`, so the check-and-set is one server round-trip without a leaked race window.
- `append`/`prepend` and `increment`/`decrement` run a short optimistic retry loop on top of the same `REPLACE_IF_EQUALS` operator (Ignite has no scriptable server-side equivalent of Redis Lua).
- `addServer()` endpoints are treated as independent shards, identically to the Redis backend. For a real Ignite cluster, register one endpoint and let the cluster handle partitioning.
- `flush($delay > 0)` returns `RES_NOT_SUPPORTED` (`flush delay not supported on Ignite`); `flush(0)` clears the cache via `OP_CACHE_CLEAR`.
- Per-key TTL on `set` / `setMulti` is silently ignored. The shared cache is opened with whatever expiry policy is configured server-side; if none is configured, entries live until they are explicitly overwritten or removed. `touch()` reduces to a key-existence check (returns `RES_SUCCESS` if the key is present, `RES_NOTFOUND` otherwise) and the new expiration value is not applied.
- `setSaslAuthData()` / `setEncodingKey()` return `RES_NOT_SUPPORTED` here as in every other backend (the unsupported list under "Explicitly unsupported" applies).

## Backend differences

The PECL surface is the same across all three clients; the table below lists the
places where backend semantics diverge in observable ways.

| Topic | Memcached (meta) | Redis (RESP2 + Lua) | Apache Ignite (thin client) |
| --- | --- | --- | --- |
| Default port | `11211` | `6379` | `10800` |
| Max key length | 250 B (protocol-mandated) | 65 536 B | 65 536 B |
| Connection-string forms | `host:port`, `host:port:weight` | `host[:port]`, `redis://[user:pass@]host:port[/db]`, `rediss://...` (auth via `AUTH`, db via `SELECT`) | `host[:port]` |
| Multi-server endpoints | true client-side sharding (Ketama / modula) | independent shards via the same `ServerSelector`; for Redis Cluster register a single endpoint | independent shards; for an Ignite cluster register one endpoint and let the server-side partitioner do the work |
| TTL semantics on `set` / `setMulti` | ≤30 days → relative seconds, >30 days → absolute Unix ts | same as memcached (normalized client-side before `EVAL`) | per-key TTL **silently ignored**; expiry policy is whatever the shared cache is configured with on the server |
| `touch($key, $exp)` | sets new TTL via meta `mt`/`mg` semantics | sets new TTL atomically via Lua | reduces to existence check — returns `RES_SUCCESS` / `RES_NOTFOUND`, **does not** change the entry's expiry |
| `flush(0)` | text `flush_all` | RESP `FLUSHDB` | `OP_CACHE_CLEAR` |
| `flush($delay > 0)` | text `flush_all <delay>` (server-side scheduled flush) | `RES_NOT_SUPPORTED` (`flush delay not supported on Redis`) | `RES_NOT_SUPPORTED` (`flush delay not supported on Ignite`) |
| CAS implementation | meta `cs` / `cas` token (one round-trip) | Lua `EVAL` that compares + swaps atomically | 63-bit positive token kept inside a 16-byte value header; commit via `OP_CACHE_REPLACE_IF_EQUALS` (single round-trip) |
| `append` / `prepend` atomicity | native meta `ms` mode | server-side Lua | optimistic retry loop on `REPLACE_IF_EQUALS` |
| `increment` / `decrement` (with `initial_value` + `expiry`) | meta `ma` with autovivify | Lua script seeds the long counter and applies expiry atomically | same `initial_value` + `expiry` semantics via the optimistic-retry loop |
| `setMulti` / `setMultiByKey` batching | grouped meta `ms` writes per server, replies read in order | pipelined `EVALSHA` per server (one TCP round-trip, replies read in order) | per-item sequential stores (no `OP_CACHE_PUT_ALL` pipelining yet) |
| `getStats` | classic text `stats` (`stats items`, `stats slabs`, …) | RESP `INFO` mapped to memcached-shaped sections (general / items / slabs / sizes / `INFO <section>`) | thin-client status snapshot mapped to a memcached-shaped section set |
| `getVersion` | text `version` | `INFO server` → `redis_version` | thin-client status snapshot version field |
| `getAllKeys` | `lru_crawler metadump all` on memcached ≥ 1.5.6, else `stats items` / `stats cachedump` | `SCAN MATCH pm:v1:*` with `COUNT 500` per server, stripped to logical keys | `cacheScanKeys` per server over the `PURECACHE_V1` cache |
| `delete($key, $time > 0)` | `RES_NOT_SUPPORTED` (delayed delete not exposed in the meta protocol) | `RES_NOT_SUPPORTED` | `RES_NOT_SUPPORTED` |
| Built-in authentication | none (`setSaslAuthData()` → `RES_NOT_SUPPORTED`) | URL-embedded `AUTH user pass` + optional `SELECT db` in the handshake | none |
| `setSaslAuthData()` / `setEncodingKey()` | `RES_NOT_SUPPORTED` | `RES_NOT_SUPPORTED` | `RES_NOT_SUPPORTED` |
| Persistent-connection pool (`persistent_id`) | isolated per backend via the shared `PersistentStateRegistry` trait — the same id on different backends does **not** collide |||
| Serializer / compression / `OPT_USER_FLAGS` | identical (handled by `ValueCodec` before the wire) |||

If an unsupported call has a per-backend reason, it lands in `getResultMessage()`
verbatim (`flush delay not supported on Redis`, etc.), so application logs can
attribute parity gaps to the right backend.

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

To run the Redis-backed integration tests, use:

```bash
composer test:redis
```

To run the Apache Ignite integration tests:

```bash
composer test:ignite
```

The wrapper provisions Ignite on demand, mirroring how `test:integration` and `test:redis` spawn their backends:

1. If `IGNITE_TEST_HOST` / `IGNITE_TEST_PORT` point at a reachable thin-client endpoint, the wrapper uses it as-is.
2. Otherwise it looks for `bin/ignite.sh` under `IGNITE_HOME` or inside `cache/ignite/apache-ignite-${IGNITE_VERSION}-bin/`.
3. If no local distribution is found, it downloads `apache-ignite-${IGNITE_VERSION}-bin.zip` (default `IGNITE_VERSION=2.16.0`) from `archive.apache.org`/`dlcdn.apache.org` into `cache/ignite/`, verifies the archive with `unzip -tq`, and extracts it.
4. It then writes a minimal Spring XML config on a free local port, starts the JVM via `bash ignite.sh`, redirects Ignite logs to `cache/ignite/ignite-server.log` (only the start banner reaches the console), waits for the TCP port, and tears the whole process tree down at the end (the Ignite shell wrapper does not `exec` the JVM, so the runner pgrep-walks the descendants and SIGTERM/SIGKILLs the JVM directly).

Requirements: a JDK 11+ runtime on `PATH` plus the `curl` and `unzip` binaries (both shipped with macOS and most Linux distros). The Apache Ignite jars are cached under `cache/ignite/`, which is git-ignored.

If you prefer a containerized Ignite, `docker compose up -d ignite` from the bundled `docker-compose.yml` still works — the wrapper will detect the running container on `127.0.0.1:10800` and skip the local bootstrap.

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

- Keying and routing: `OPT_PREFIX_KEY`, `OPT_HASH`, `OPT_LIBKETAMA_HASH` (PECL-quirk no-op setter that read-aliases `OPT_HASH` and only rejects `HASH_HSIEH`), `OPT_DISTRIBUTION`, `OPT_LIBKETAMA_COMPATIBLE`, `OPT_HASH_WITH_PREFIX_KEY`, `setBucket`.
- Value encoding: `OPT_SERIALIZER`, `OPT_COMPRESSION`, `OPT_COMPRESSION_TYPE`, `OPT_COMPRESSION_LEVEL`, `OPT_USER_FLAGS`, `OPT_ITEM_SIZE_LIMIT`, `OPT_ALLOW_SERIALIZED_CLASSES`.
- I/O behavior implemented by this client: `OPT_CONNECT_TIMEOUT`, `OPT_RECV_TIMEOUT`, `OPT_SEND_TIMEOUT`, `OPT_NOREPLY`, `OPT_BUFFER_WRITES`, `OPT_VERIFY_KEY`, `OPT_TCP_NODELAY`, `OPT_TCP_KEEPALIVE`, `OPT_SOCKET_SEND_SIZE`, `OPT_SOCKET_RECV_SIZE`.
- `OPT_NO_BLOCK` is accepted and reported like PECL for configuration compatibility, but operations still use blocking PHP streams with configured timeouts rather than libmemcached's non-blocking state machine.
- Local application storage: `OPT_USER_DATA`.

### Explicitly unsupported

- Protocol/network modes not implemented by the pure PHP meta client: `OPT_BINARY_PROTOCOL`, `OPT_USE_UDP`.
- Libmemcached failover/tuning options that require native client internals: `OPT_SORT_HOSTS`, `OPT_REMOVE_FAILED_SERVERS`, `OPT_RANDOMIZE_REPLICA_READ`, `OPT_CORK`, retry/dead-server limits, IO watermarks/prefetch, and replica read options.
- File/native-extension integration options: `OPT_LOAD_FROM_FILE`, `OPT_SUPPORT_CAS`, `OPT_TCP_KEEPIDLE`.
- Authentication/encryption: `setSaslAuthData()` and `setEncodingKey()` return `RES_NOT_SUPPORTED`.
- `delete($key, $time)` / `deleteByKey()` / `deleteMulti*()` with a positive delayed-delete time return `RES_NOT_SUPPORTED` on every backend — only immediate deletion is supported. Negative `$time` is rejected with `RES_INVALID_ARGUMENTS`.

### Security note: PHP-serialized objects

`SERIALIZER_PHP` payloads are deserialized with `allowed_classes = false` by default, so cached PHP objects rehydrate as `__PHP_Incomplete_Class` instances. This mirrors the recommended modern PHP usage and prevents object-injection gadgets from untrusted cache contents.

PECL `Memcached` does not pin `allowed_classes`, so applications that rely on the legacy behavior can opt back in:

```php
$client->setOption(MemcachedClient::OPT_ALLOW_SERIALIZED_CLASSES, true);
```

When enabled, PHP-serialized values are read with `allowed_classes = true` for full PECL parity. `SERIALIZER_IGBINARY` is unaffected — it does not expose an `allowed_classes` toggle.

### Compression notes

- `COMPRESSION_FASTLZ` requires a compatible `fastlz_*` extension. If it is unavailable, values are stored uncompressed instead of using a non-compatible stand-in.
- `COMPRESSION_ZLIB` works when `ext-zlib` is available.
- `COMPRESSION_ZSTD` requires a compatible Zstandard extension.
