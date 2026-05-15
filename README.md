# purecache/cache

Pure PHP 8.3 implementation of the PECL `Memcached` API on top of pluggable backends: the memcached meta protocol, Redis (RESP2), and Apache Ignite (thin client binary protocol). All memcached cache operations use the **memcached meta protocol** (`mg`, `ms`, `md`, `ma`, `me`, `mn`) on the TCP connection.

## Who is this for?

This module is aimed at two audiences:

- **Teams that want to move to the memcached meta protocol** (`mg`/`ms`/`md`/`ma`/`me`/`mn`). PECL `ext-memcached` is built on libmemcached, which still speaks the classic ASCII / binary protocols and has **no support for the meta protocol** — so the only way to talk meta from PHP today is to bypass libmemcached entirely. `PureCache\Memcached\MemcachedClient` does exactly that: it speaks meta directly on the TCP socket while keeping the `Memcached::*` API shape, so existing code keeps compiling and running.
- **Teams that want to swap the cache backend cheaply.** The same PECL-shaped API is implemented on top of memcached (meta), Redis (RESP2 + Lua), and Apache Ignite (thin client). Migrating from one to another is a one-line change (`MemcachedClient` → `RedisClient` / `IgniteClient`, or via `ClientFactory::create()`); application code, `OPT_*` configuration, serializers, CAS, counters, and session handling stay the same. You can drop in `bootstrap-alias.php` to keep the global `Memcached` class working when `ext-memcached` is not installed, which makes the swap reversible.

If you already use PECL `Memcached` and are happy with the classic / binary protocol and a single backend, `ext-memcached` is faster (it is a C extension) — this library is for the cases above where PECL is not an option.

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
| `memcached.store_retry_count` | `0` | `OPT_STORE_RETRY_COUNT` | On a primary `RES_FAILURE` (write threw, connection broke, …) the client retries the write onto another live server up to this many times. Applies to `set`/`add`/`replace`/`cas` across every backend; non-failure outcomes (`RES_NOTSTORED`, `RES_DATA_EXISTS`, `RES_E2BIG`, …) are surfaced verbatim — same as libmemcached. |
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
| `memcached.sess_number_of_replicas` | `0` | Forwarded to `OPT_NUMBER_OF_REPLICAS`; writes fan-out to the primary plus this many replicas, and the session-handler write loop uses the same value in its retry formula. |
| `memcached.sess_randomize_replica_read` | `Off` | Forwarded to `OPT_RANDOMIZE_REPLICA_READ`; when combined with a non-zero replica count, session reads pick a random live replica per call. |
| `memcached.sess_remove_failed_servers` | `Off` | Forwarded to `OPT_REMOVE_FAILED_SERVERS` (failed servers are routed around) and additionally enables the `1 + replicas * (failure_limit + 1)` retry loop inside `write()` — same formula PECL uses. |
| `memcached.sess_server_failure_limit` | `0` | Forwarded to `OPT_SERVER_FAILURE_LIMIT` (caps consecutive failures before a server is taken out of rotation) and feeds the write-retry formula above. |
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
- `setSaslAuthData()` returns `RES_NOT_SUPPORTED` (no binary SASL handshake on the thin client). `setEncodingKey()` works the same as on every other backend — encryption happens client-side in the value codec before each `OP_CACHE_PUT` and after each `OP_CACHE_GET`, so the encoded payload is opaque to the server.

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
| `setSaslAuthData()` | `RES_NOT_SUPPORTED` | `RES_NOT_SUPPORTED` (use the connection-string URL) | `RES_NOT_SUPPORTED` |
| `setEncodingKey()` | client-side AES via `ValueCodec` (libmemcached-compat AES-128-ECB or AEAD AES-256-GCM) | identical — same codec runs before/after Lua | identical — same codec runs before/after the byte-array wrapper |
| Persistent-connection pool (`persistent_id`) | isolated per backend via the shared `PersistentStateRegistry` trait — the same id on different backends does **not** collide |||
| Serializer / compression / `OPT_USER_FLAGS` | identical (handled by `ValueCodec` before the wire) |||

If an unsupported call has a per-backend reason, it lands in `getResultMessage()`
verbatim (`flush delay not supported on Redis`, etc.), so application logs can
attribute parity gaps to the right backend.

## Static analysis

PHPStan and Psalm both cover `src/`, `tests/`, and `bootstrap-alias.php` (see `config/phpstan.neon` and `config/psalm.xml`). A full local gate matches CI expectations:

```bash
composer phpstan
composer psalm
composer check   # phpstan + psalm + style/rector dry-runs + unit tests (with and without optional extensions)
```

Psalm stores its cache under `cache/psalm/` (the whole `cache/` tree is git-ignored).

`config/psalm.xml` enables **`findUnusedCode="true"`** so unused classes/methods/properties surface during analysis. A few patterns are intentionally noisy for a PECL-shaped library (for example `private __construct` on static-only helpers, interface methods on `CacheClient`, PHPUnit `#[DataProvider]` callables, and promoted `readonly` fields on `KetamaContinuum` that Psalm does not always treat as read). Those are narrowed with `issueHandlers` in the same config file rather than turning the check off globally.

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
- I/O behavior implemented by this client: `OPT_CONNECT_TIMEOUT`, `OPT_RECV_TIMEOUT`, `OPT_SEND_TIMEOUT`, `OPT_POLL_TIMEOUT` (fallback per-write `stream_select()` budget when `OPT_SEND_TIMEOUT` is unset; default `1000` ms), `OPT_NOREPLY`, `OPT_BUFFER_WRITES`, `OPT_VERIFY_KEY`, `OPT_TCP_NODELAY`, `OPT_TCP_KEEPALIVE`, `OPT_SOCKET_SEND_SIZE`, `OPT_SOCKET_RECV_SIZE`, `OPT_IO_BYTES_WATERMARK` (auto-flushes the meta-protocol write buffer once this many bytes are queued), `OPT_IO_MSG_WATERMARK` (same for `OPT_BUFFER_WRITES`, but counts `bufferWrite()` calls — one logical command each — so small commands still flush before a huge byte buffer builds up), `OPT_CORK` (Linux `TCP_CORK`; no-op elsewhere — matches libmemcached).
- `OPT_IO_KEY_PREFETCH` (memcached backend only): when set to a positive integer, `getMulti` / `getDelayed`+`fetch` pipelines meta `mg` commands in windows of that many keys per server connection (send N, read N, repeat). When unset or `0`, all keys for a shard are written before any replies are read (maximum pipelining for blocking streams). This approximates libmemcached’s keyed read-ahead depth without a non-blocking I/O engine.
- Server-pool management implemented across every backend: `OPT_SORT_HOSTS`, `OPT_REMOVE_FAILED_SERVERS`, `OPT_SERVER_FAILURE_LIMIT`, `OPT_SERVER_TIMEOUT_LIMIT`, `OPT_RETRY_TIMEOUT`, `OPT_DEAD_TIMEOUT`, `OPT_STORE_RETRY_COUNT`, `OPT_NUMBER_OF_REPLICAS`, `OPT_RANDOMIZE_REPLICA_READ`. Writes fan-out to the primary plus `OPT_NUMBER_OF_REPLICAS` replicas; reads pick a random replica when `OPT_RANDOMIZE_REPLICA_READ` is on; failed primaries are routed around for the configured retry/dead window.
- `OPT_NO_BLOCK` is accepted and reported like PECL for configuration compatibility, but operations still use blocking PHP streams with configured timeouts rather than libmemcached's non-blocking state machine.
- Local application storage: `OPT_USER_DATA`.
- `OPT_LOAD_FROM_FILE`: supported on every backend. A libmemcached-style configuration file is parsed in PHP (`LibmemcachedConfigFile`) and each directive is applied through the normal `setOption()` machinery — this is **not** a native `libmemcached` / PECL extension hook; there is no C binding in PureCache.
- `OPT_SUPPORT_CAS`: `setOption()` / `getOption()` accept the dial for PECL parity (getter reports `0` / `1` like PECL). Turning it off does **not** strip CAS from the wire or disable `cas()` — the memcached meta client decodes CAS whenever the server sends it, and the Redis / Ignite adapters implement real CAS semantics regardless of this flag.
- `OPT_TCP_KEEPIDLE`: **Memcached** — when `OPT_TCP_KEEPALIVE` is enabled and idle seconds are > 0, the value is applied with `socket_set_option` on newly opened TCP streams (changing it rebuilds the pool). On platforms where PHP cannot set the knob (notably Windows stream sockets), it is a documented no-op, matching libmemcached’s portability story. **Redis** — `setOption()` returns `RES_NOT_SUPPORTED` (`RedisClient` explicitly rejects TCP keepalive tuning options). **Ignite** — the value is accepted and stored for `getOption()` parity, but the thin-client transport does not apply it to sockets today (no observable effect).

### Explicitly unsupported

- Protocol/network modes not implemented by the pure PHP meta client: `OPT_BINARY_PROTOCOL`, `OPT_USE_UDP`.
- Authentication: `setSaslAuthData()` returns `RES_NOT_SUPPORTED` on every backend — there is no binary SASL handshake in the pure-PHP transport. Redis credentials are configured via the connection-string URL instead (`redis://user:pass@host/db`).
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

### Encryption (`setEncodingKey`)

`setEncodingKey($key)` enables transparent AES encryption of cached values across every backend. The key is hashed client-side and fed into the value codec, so the cipher runs **after** serialization/compression and **before** the bytes hit the wire — no backend ever sees plaintext, and `OPT_SERIALIZER` / `OPT_COMPRESSION` keep working unchanged on top of an encrypted pool.

Two modes are exposed via `OPT_ENCODING_MODE` (PureCache extension — no PECL counterpart):

- `ENCODING_MODE_LIBMEMCACHED` (default) — bit-compatible with libmemcached's `memcached_set_encoding_key()`: AES-128-ECB with zero padding, key = `md5(raw_user_key)`. No flag bit is set on stored entries. Useful only for round-tripping existing libmemcached-encrypted caches; the algorithm has no integrity check and leaks repeating 16-byte plaintext blocks.
- `ENCODING_MODE_AEAD` — modern AEAD: AES-256-GCM with a per-value random 12-byte nonce, 16-byte authentication tag, and a marker bit on the stored `flags`. Existing unencrypted entries in the same pool keep round-tripping unchanged.

Both modes require `ext-openssl`; `setEncodingKey()` returns `RES_NOT_SUPPORTED` (`encoding requires ext-openssl`) when it is missing, and `RES_INVALID_ARGUMENTS` on an empty key. `setOption(OPT_ENCODING_MODE, …)` clears any previously-installed key so the next encoded value uses the new format unambiguously. `append`/`prepend` against an active encoding key are rejected with `RES_NOTSTORED` — concatenating onto existing ciphertext would corrupt the entry.

`HAVE_ENCODING` mirrors libmemcached's compile-time flag: at the PHP layer it is simply `extension_loaded('openssl')`.
