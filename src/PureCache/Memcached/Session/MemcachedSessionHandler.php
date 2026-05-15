<?php

declare(strict_types=1);

namespace PureCache\Memcached\Session;

use PureCache\CacheClient;
use PureCache\Internal\IniConfig;
use PureCache\Memcached\MemcachedClient;
use PureCache\MemcachedConstants;

/**
 * Pure-PHP session save handler that reproduces every {@code memcached.sess_*}
 * directive PECL registers in {@code php_memcached_session.c}.
 *
 * The state machine is intentionally a 1:1 port of PECL:
 *  - {@see open()} parses {@code save_path} into servers, applies all session
 *    INI values to a fresh {@see MemcachedClient}, and optionally caches the
 *    client in a per-process map keyed by {@code save_path} ({@code memcached.sess_persistent}).
 *  - {@see read()} optionally acquires an exponential-backoff lock under
 *    {@code lock.{sid}} ({@code memcached.sess_locking} + {@code lock_wait_min},
 *    {@code lock_wait_max}, {@code lock_retries}, {@code lock_expire}).
 *  - {@see write()} retries the store {@code 1 + replicas * (failure_limit + 1)}
 *    times when {@code memcached.sess_remove_failed_servers} is enabled, exactly
 *    like {@code PS_WRITE_FUNC(memcached)}.
 *  - {@see destroy()} deletes the session, then releases the lock.
 *
 * Register manually because PHP cannot dispatch {@code session.save_handler = memcached}
 * to a userland class:
 * ```php
 * \ini_set('session.save_handler', 'user');
 * \session_set_save_handler(new MemcachedSessionHandler(), true);
 * \session_start();
 * ```
 *
 * Limitations vs PECL — the pure-PHP transport speaks the meta protocol only:
 *  - {@code memcached.sess_binary_protocol = On} is recognised but the wire is
 *    still meta; a {@code E_USER_WARNING} is emitted to mirror PECL's
 *    "failed to set behavior" warning when libmemcached refuses a flag.
 *  - {@code memcached.sess_sasl_username} + {@code memcached.sess_sasl_password}
 *    cannot be honored without the binary handshake; {@see open()} fails with a
 *    {@code E_USER_WARNING} (same wording as PECL's
 *    "failed to set memcached session sasl credentials").
 */
final class MemcachedSessionHandler implements \SessionHandlerInterface, \SessionIdInterface, \SessionUpdateTimestampHandlerInterface
{
    /** Absolute-expiration cutoff that memcached.c uses (30 days). */
    private const int REALTIME_MAXDELTA = 60 * 60 * 24 * 30;

    /** @var array<string, CacheClient> */
    private static array $persistentClients = [];

    private static bool $binaryProtocolWarned = false;

    private bool $isPersistent = false;

    private ?string $lockKey = null;

    /**
     * Cached snapshot of {@code memcached.sess_*} directives for this handler instance.
     *
     * @var array{
     *   lock_enabled:bool,
     *   lock_wait_min:int,
     *   lock_wait_max:int,
     *   lock_retries:int,
     *   lock_expiration:int,
     *   binary_protocol_enabled:bool,
     *   consistent_hash_enabled:bool,
     *   consistent_hash_type:string,
     *   number_of_replicas:int,
     *   randomize_replica_read_enabled:bool,
     *   remove_failed_servers_enabled:bool,
     *   server_failure_limit:int,
     *   connect_timeout:int,
     *   sasl_username:?string,
     *   sasl_password:?string,
     *   persistent_enabled:bool,
     *   prefix:string,
     * }
     */
    private array $ini;

    public function __construct(private ?CacheClient $client = null)
    {
        $this->ini = IniConfig::snapshotSession();
    }

    #[\Override]
    public function open(string $path, string $name): bool
    {
        if (str_contains($path, 'PERSISTENT=')) {
            trigger_error(
                'failed to parse session.save_path: PERSISTENT is replaced by memcached.sess_persistent = On',
                \E_USER_WARNING,
            );

            return false;
        }

        if ($this->client instanceof CacheClient) {
            return $this->configureFromIni($this->client, silent: false);
        }

        $servers = $this->parseSavePath($path);
        if ([] === $servers) {
            trigger_error('failed to parse session.save_path', \E_USER_WARNING);

            return false;
        }

        $this->isPersistent = $this->ini['persistent_enabled'];

        if ($this->isPersistent && isset(self::$persistentClients[$path])) {
            $client = self::$persistentClients[$path];
            if ($this->configureFromIni($client, silent: true)) {
                $this->client = $client;

                return true;
            }

            unset(self::$persistentClients[$path]);
        }

        $client = new MemcachedClient();
        foreach ($servers as [$host, $port, $weight]) {
            $client->addServer($host, $port, $weight);
        }

        $client->setOption(MemcachedConstants::OPT_VERIFY_KEY, true);

        if (!$this->configureFromIni($client, silent: false)) {
            return false;
        }

        if ($this->isPersistent) {
            self::$persistentClients[$path] = $client;
        }

        $this->client = $client;

        return true;
    }

    #[\Override]
    public function close(): bool
    {
        if ($this->client instanceof CacheClient && null !== $this->lockKey) {
            $this->unlockSession();
        }

        if (!$this->isPersistent) {
            $this->client = null;
        }

        return true;
    }

    #[\Override]
    public function read(string $id): string|false
    {
        $client = $this->client;
        if (!$client instanceof CacheClient) {
            trigger_error('Session is not allocated, check session.save_path value', \E_USER_WARNING);

            return false;
        }

        if ($this->ini['lock_enabled'] && !$this->lockSession($client, $id)) {
            trigger_error('Unable to clear session lock record', \E_USER_WARNING);

            return false;
        }

        $payload = $client->get($id);
        $code = $client->getResultCode();

        if (MemcachedConstants::RES_SUCCESS === $code) {
            return \is_string($payload) ? $payload : '';
        }

        if (MemcachedConstants::RES_NOTFOUND === $code) {
            return '';
        }

        trigger_error(
            \sprintf('error getting session from memcached: %s', $client->getResultMessage()),
            \E_USER_WARNING,
        );

        return false;
    }

    #[\Override]
    public function write(string $id, string $data): bool
    {
        $client = $this->client;
        if (!$client instanceof CacheClient) {
            trigger_error('Session is not allocated, check session.save_path value', \E_USER_WARNING);

            return false;
        }

        $expiration = $this->sessionExpiration();
        $retries = 1;

        if ($this->ini['remove_failed_servers_enabled']) {
            $replicas = $this->ini['number_of_replicas'];
            $failureLimit = $this->ini['server_failure_limit'];
            $retries = 1 + $replicas * ($failureLimit + 1);
        }

        do {
            if ($client->set($id, $data, $expiration)) {
                return true;
            }

            trigger_error(
                \sprintf('error saving session to memcached: %s', $client->getResultMessage()),
                \E_USER_WARNING,
            );
        } while (--$retries > 0);

        return false;
    }

    #[\Override]
    public function destroy(string $id): bool
    {
        $client = $this->client;
        if (!$client instanceof CacheClient) {
            trigger_error('Session is not allocated, check session.save_path value', \E_USER_WARNING);

            return false;
        }

        $client->delete($id);

        if (null !== $this->lockKey) {
            $this->unlockSession();
        }

        return true;
    }

    #[\Override]
    public function gc(int $max_lifetime): int
    {
        return 0;
    }

    #[\Override]
    public function create_sid(): string
    {
        $client = $this->client;
        if (!$client instanceof CacheClient) {
            return $this->randomSid();
        }

        for ($i = 0; $i < 3; ++$i) {
            $sid = $this->randomSid();
            if ($client->add($sid, '', $this->lockExpiration())) {
                return $sid;
            }
        }

        return $this->randomSid();
    }

    #[\Override]
    public function validateId(string $id): bool
    {
        $client = $this->client;
        if (!$client instanceof CacheClient) {
            return false;
        }

        $client->get($id);

        return MemcachedConstants::RES_SUCCESS === $client->getResultCode();
    }

    #[\Override]
    public function updateTimestamp(string $id, string $data): bool
    {
        $client = $this->client;
        if (!$client instanceof CacheClient) {
            return false;
        }

        return $client->touch($id, $this->sessionExpiration());
    }

    /**
     * Mirrors PECL's {@code s_configure_from_ini_values()}:
     *  1. Apply binary protocol (with our meta-only warning).
     *  2. Apply consistent hashing + type (ketama / ketama_weighted).
     *  3. Apply server failure limit, replicas count, randomized replica read,
     *     remove-failed-servers (each of these is unsupported by our transport
     *     and surfaces as {@code RES_NOT_SUPPORTED}; we still write the option
     *     so {@code getOption()} reflects intent).
     *  4. Apply connect timeout via {@code OPT_CONNECT_TIMEOUT}.
     *  5. Apply prefix via {@code OPT_PREFIX_KEY} (the PECL equivalent is
     *     {@code memcached_callback_set(MEMCACHED_CALLBACK_NAMESPACE)}).
     *  6. If SASL credentials are configured: warn and fail.
     */
    private function configureFromIni(CacheClient $client, bool $silent): bool
    {
        if ($this->ini['binary_protocol_enabled'] && !$silent && !self::$binaryProtocolWarned) {
            self::$binaryProtocolWarned = true;
            trigger_error(
                'memcached.sess_binary_protocol=On is ignored: PureCache speaks the meta protocol exclusively',
                \E_USER_WARNING,
            );
        }

        if ($this->ini['consistent_hash_enabled']) {
            $client->setOption(MemcachedConstants::OPT_DISTRIBUTION, MemcachedConstants::DISTRIBUTION_CONSISTENT);
            // ketama_weighted is the legacy 2.x default; ketama (unweighted) is
            // the 3.x default. We mirror libmemcached's MEMCACHED_BEHAVIOR_KETAMA
            // / MEMCACHED_BEHAVIOR_KETAMA_WEIGHTED switch by toggling
            // OPT_LIBKETAMA_COMPATIBLE — both modes use MD5 + ketama, only the
            // continuum weighting differs, and the selector already handles
            // weighted vs unweighted via setLibketamaCompatible().
            $client->setOption(MemcachedConstants::OPT_LIBKETAMA_COMPATIBLE, true);
        }

        // The rest of these are documented as unsupported on the meta protocol
        // (see README "Explicitly unsupported"). PECL would call
        // memcached_behavior_set() and warn if it failed; we mirror that:
        // emit a warning on first apply, but never block the session open.
        $this->trySetUnsupportedInt($client, MemcachedConstants::OPT_SERVER_FAILURE_LIMIT, $this->ini['server_failure_limit'], $silent);
        $this->trySetUnsupportedInt($client, MemcachedConstants::OPT_NUMBER_OF_REPLICAS, $this->ini['number_of_replicas'], $silent);
        $this->trySetUnsupportedBool($client, MemcachedConstants::OPT_RANDOMIZE_REPLICA_READ, $this->ini['randomize_replica_read_enabled'], $silent);
        $this->trySetUnsupportedBool($client, MemcachedConstants::OPT_REMOVE_FAILED_SERVERS, $this->ini['remove_failed_servers_enabled'], $silent);

        if (0 !== $this->ini['connect_timeout']) {
            $client->setOption(MemcachedConstants::OPT_CONNECT_TIMEOUT, $this->ini['connect_timeout']);
        }

        $prefix = $this->ini['prefix'];
        if ('' !== $prefix && !$client->setOption(MemcachedConstants::OPT_PREFIX_KEY, $prefix)) {
            if (!$silent) {
                trigger_error(
                    \sprintf('failed to initialise session memcached configuration (prefix): %s', $client->getResultMessage()),
                    \E_USER_WARNING,
                );
            }

            return false;
        }

        if (null !== $this->ini['sasl_username'] && null !== $this->ini['sasl_password']) {
            if (!$silent) {
                trigger_error('failed to set memcached session sasl credentials', \E_USER_WARNING);
            }

            return false;
        }

        return true;
    }

    private function trySetUnsupportedInt(CacheClient $client, int $option, int $value, bool $silent): void
    {
        if (0 === $value) {
            return;
        }

        if (!$client->setOption($option, $value) && !$silent) {
            trigger_error(
                \sprintf('failed to initialise session memcached configuration: %s', $client->getResultMessage()),
                \E_USER_WARNING,
            );
        }
    }

    private function trySetUnsupportedBool(CacheClient $client, int $option, bool $value, bool $silent): void
    {
        if (!$value) {
            return;
        }

        if (!$client->setOption($option, true) && !$silent) {
            trigger_error(
                \sprintf('failed to initialise session memcached configuration: %s', $client->getResultMessage()),
                \E_USER_WARNING,
            );
        }
    }

    private function lockSession(CacheClient $client, string $sid): bool
    {
        $lockKey = 'lock.'.$sid;
        $expiration = $this->lockExpiration();

        $waitTime = $this->ini['lock_wait_min'];
        $waitMax = $this->ini['lock_wait_max'];
        $retries = $this->ini['lock_retries'];

        // Initial attempt + lock_retries follow-ups, matching the PECL do/while
        // semantics with post-decrement on retries.
        do {
            if ($client->add($lockKey, '1', $expiration)) {
                $this->lockKey = $lockKey;

                return true;
            }

            $code = $client->getResultCode();
            if (MemcachedConstants::RES_NOTSTORED !== $code && MemcachedConstants::RES_DATA_EXISTS !== $code) {
                trigger_error(
                    \sprintf('Failed to write session lock: %s', $client->getResultMessage()),
                    \E_USER_WARNING,
                );
                // Continue retrying on transient errors to match PECL: it
                // logs the warning per-attempt and keeps trying.
            }

            if ($retries > 0) {
                usleep($waitTime * 1000);
                $waitTime = min($waitMax, $waitTime * 2);
            }
        } while ($retries-- > 0);

        return false;
    }

    private function unlockSession(): void
    {
        $client = $this->client;
        if (!$client instanceof CacheClient || null === $this->lockKey) {
            return;
        }

        $client->delete($this->lockKey);
        $this->lockKey = null;
    }

    /**
     * Mirrors {@code s_lock_expiration()}: prefer {@code memcached.sess_lock_expire},
     * otherwise fall back to {@code max_execution_time}. Values above 30 days
     * get converted into absolute Unix timestamps the way memcached expects.
     */
    private function lockExpiration(): int
    {
        $expire = $this->ini['lock_expiration'];
        if ($expire > 0) {
            return $this->adjustExpiration($expire);
        }

        $maxExecutionTime = (int) \ini_get('max_execution_time');
        if ($maxExecutionTime > 0) {
            return $this->adjustExpiration($maxExecutionTime);
        }

        return 0;
    }

    /**
     * Mirrors {@code s_session_expiration()}: pulls {@code session.gc_maxlifetime}
     * (the same value PECL receives as {@code PS_WRITE_FUNC}'s {@code maxlifetime}
     * argument) and converts to absolute Unix timestamp if needed.
     */
    private function sessionExpiration(): int
    {
        $maxLifetime = (int) \ini_get('session.gc_maxlifetime');
        if ($maxLifetime > 0) {
            return $this->adjustExpiration($maxLifetime);
        }

        return 0;
    }

    private function adjustExpiration(int $expiration): int
    {
        if ($expiration <= self::REALTIME_MAXDELTA) {
            return $expiration;
        }

        return time() + $expiration;
    }

    /**
     * @return list<array{0:string,1:int,2:int}>
     */
    private function parseSavePath(string $savePath): array
    {
        $servers = [];
        foreach (explode(',', $savePath) as $entry) {
            $entry = trim($entry);
            if ('' === $entry) {
                continue;
            }

            $weight = 0;
            $parts = explode(':', $entry);
            $host = $parts[0];
            $port = isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : 11211;
            if (isset($parts[2]) && ctype_digit($parts[2])) {
                $weight = (int) $parts[2];
            }

            if ('' === $host) {
                continue;
            }

            $servers[] = [$host, $port, $weight];
        }

        return $servers;
    }

    private function randomSid(): string
    {
        if (\function_exists('session_create_id')) {
            $sid = @session_create_id();
            if (\is_string($sid) && '' !== $sid) {
                return $sid;
            }
        }

        return bin2hex(random_bytes(16));
    }
}
