<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\AbstractCacheClient;
use PureCache\MemcachedConstants;

/**
 * Parser and applier for libmemcached's configuration-file DSL — the same
 * format consumed by libmemcached's {@code memcached()} constructor and the
 * file passed to {@code MEMCACHED_BEHAVIOR_LOAD_FROM_FILE}. PECL's
 * {@code OPT_LOAD_FROM_FILE} forwards the path straight to libmemcached;
 * we re-implement the DSL in PHP so {@code setOption(OPT_LOAD_FROM_FILE, …)}
 * has the same observable effect (server pool + behaviour bits populated
 * from the file) without needing libmemcached.
 *
 * Directives recognised (whitespace-separated, one logical token each):
 *  - {@code --SERVER=host[:port][/?weight]}
 *  - {@code --SOCKET="path"[/?weight]} (unix socket)
 *  - {@code --VERIFY-KEY}, {@code --SUPPORT-CAS}, {@code --NOREPLY},
 *    {@code --BUFFER-REQUESTS}, {@code --HASH-WITH-NAMESPACE},
 *    {@code --REMOVE-FAILED-SERVERS} / {@code --REMOVE_FAILED_SERVERS},
 *    {@code --SORT-HOSTS}, {@code --RANDOMIZE-REPLICA-READ},
 *    {@code --BINARY-PROTOCOL}, {@code --USE-UDP}, {@code --TCP-NODELAY},
 *    {@code --TCP-KEEPALIVE} — boolean toggles
 *  - {@code --CONNECT-TIMEOUT=ms}, {@code --RCV-TIMEOUT=ms},
 *    {@code --SND-TIMEOUT=ms}, {@code --POLL-TIMEOUT=ms},
 *    {@code --RETRY-TIMEOUT=ms}, {@code --SERVER-FAILURE-LIMIT=n},
 *    {@code --SOCKET-RECV-SIZE=n}, {@code --SOCKET-SEND-SIZE=n},
 *    {@code --IO-BYTES-WATERMARK=n}, {@code --IO-MSG-WATERMARK=n},
 *    {@code --IO-KEY-PREFETCH=n}, {@code --NUMBER-OF-REPLICAS=n},
 *    {@code --TCP-KEEPIDLE=n} — integer values
 *  - {@code --HASH=name} — {@code default|md5|crc|fnv1_64|fnv1a_64|
 *    fnv1_32|fnv1a_32|hsieh|murmur|jenkins}
 *  - {@code --DISTRIBUTION=name} — {@code modula|consistent|ketama|
 *    virtual_bucket|random}
 *  - {@code --NAMESPACE=prefix} (alias for {@code OPT_PREFIX_KEY})
 *  - {@code --POOL-MIN=n}, {@code --POOL-MAX=n} — accepted no-op (no
 *    libmemcached pool in pure PHP)
 *  - {@code --CONFIGURE-FILE="path"} — reset state then recursively load
 *  - {@code INCLUDE} {@code "path"} — recursive load without reset
 *  - {@code RESET} — reset to defaults (servers cleared, options re-init)
 *  - {@code END} — stop processing
 *  - {@code ERROR} — stop and surface a parse error
 *
 * Per-directive failures are best-effort: an unrecognised directive or an
 * option that the current PureCache backend rejects emits an
 * {@code E_USER_NOTICE} but does not abort the whole file. That matches
 * libmemcached's behaviour, which silently downgrades unsupported
 * directives so a config file written for a richer build doesn't break
 * leaner consumers. Hard parse errors (malformed token, unreadable file,
 * include loop) still fail loudly with {@code RES_INVALID_ARGUMENTS}.
 */
final class LibmemcachedConfigFile
{
    /** Cap recursive {@code --CONFIGURE-FILE}/{@code INCLUDE} chains. */
    private const int MAX_INCLUDE_DEPTH = 16;

    /** Bytes hard limit per config file (DoS guard). */
    private const int MAX_FILE_BYTES = 1 << 20;

    private function __construct()
    {
    }

    /**
     * @param AbstractCacheClient<ClientCoreState> $client
     */
    public static function applyToClient(string $path, AbstractCacheClient $client): ClientOptionResult
    {
        if ('' === $path) {
            return ClientOptionResult::failure(
                MemcachedConstants::RES_INVALID_ARGUMENTS,
                'LOAD_FROM_FILE requires a non-empty filename',
            );
        }

        try {
            self::loadFile($path, $client, 0);
        } catch (ParseException $parseException) {
            return ClientOptionResult::failure(MemcachedConstants::RES_INVALID_ARGUMENTS, $parseException->getMessage());
        }

        return ClientOptionResult::success();
    }

    /**
     * @param AbstractCacheClient<ClientCoreState> $client
     *
     * @throws ParseException
     */
    private static function loadFile(string $path, AbstractCacheClient $client, int $depth): void
    {
        if ($depth >= self::MAX_INCLUDE_DEPTH) {
            throw new ParseException(\sprintf('include depth exceeded loading %s', $path));
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new ParseException(\sprintf('config file not readable: %s', $path));
        }

        $size = filesize($path);
        if (false !== $size && $size > self::MAX_FILE_BYTES) {
            throw new ParseException(\sprintf('config file too large (%d bytes): %s', $size, $path));
        }

        $contents = @file_get_contents($path);
        if (false === $contents) {
            throw new ParseException(\sprintf('failed to read config file: %s', $path));
        }

        $tokens = self::tokenize($contents);
        self::applyTokens($tokens, $client, $path, $depth);
    }

    /**
     * Tokenise the DSL: directives are whitespace-separated, double-quoted
     * strings allow embedded spaces, `#` introduces a line comment that runs
     * to end-of-line (matching libmemcached's {@code csl/parser.cc}), and an
     * explicit `END` token aborts the stream. We strip a leading UTF-8 BOM
     * so editor-saved files Just Work.
     *
     * @return list<string>
     */
    private static function tokenize(string $contents): array
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        $tokens = [];
        $len = \strlen($contents);
        $i = 0;
        while ($i < $len) {
            $ch = $contents[$i];
            if (' ' === $ch || "\t" === $ch || "\r" === $ch || "\n" === $ch) {
                ++$i;
                continue;
            }

            if ('#' === $ch) {
                // Skip until newline. We don't honour `#` mid-token (a bare
                // `--NAMESPACE=foo#bar` is one literal value, which matches
                // libmemcached's behaviour) — this branch only fires when
                // the `#` opens a fresh whitespace-delimited token.
                $nl = strpos($contents, "\n", $i + 1);
                $i = false === $nl ? $len : $nl + 1;
                continue;
            }

            if ('"' === $ch) {
                $end = strpos($contents, '"', $i + 1);
                if (false === $end) {
                    throw new ParseException('unterminated quoted string');
                }

                $tokens[] = substr($contents, $i + 1, $end - $i - 1);
                $i = $end + 1;
                continue;
            }

            // Bare token: scan until the next whitespace, but if we hit a
            // double-quote (e.g. `--NAMESPACE="my app"` or
            // `--SOCKET="/var/run/m.sock /? 2"`) consume the quoted span
            // verbatim so embedded whitespace stays attached to the
            // surrounding directive. The quotes themselves are dropped —
            // libmemcached treats them as DSL grouping syntax, not data.
            $buf = '';
            while ($i < $len) {
                $c = $contents[$i];
                if (' ' === $c || "\t" === $c || "\r" === $c || "\n" === $c) {
                    break;
                }

                if ('"' === $c) {
                    $end = strpos($contents, '"', $i + 1);
                    if (false === $end) {
                        throw new ParseException('unterminated quoted string');
                    }

                    $buf .= substr($contents, $i + 1, $end - $i - 1);
                    $i = $end + 1;
                    continue;
                }

                $buf .= $c;
                ++$i;
            }

            $tokens[] = $buf;
        }

        return $tokens;
    }

    /**
     * @param list<string>                         $tokens
     * @param AbstractCacheClient<ClientCoreState> $client
     *
     * @throws ParseException
     */
    private static function applyTokens(array $tokens, AbstractCacheClient $client, string $sourcePath, int $depth): void
    {
        $count = \count($tokens);
        $i = 0;
        while ($i < $count) {
            $token = $tokens[$i];
            ++$i;

            if ('END' === $token) {
                return;
            }

            if ('ERROR' === $token) {
                throw new ParseException(\sprintf('ERROR directive encountered in %s', $sourcePath));
            }

            if ('RESET' === $token) {
                // Matches libmemcached's documented RESET semantics:
                // "Reset memcached_st and continue to process". The server
                // list is dropped and every behaviour returns to its
                // documented default — any pre-existing OPT_PREFIX_KEY or
                // similar is wiped, just like a fresh memcached_st.
                $client->resetServerList();
                $client->setOptions(ClientOptions::defaults());

                continue;
            }

            if ('INCLUDE' === $token) {
                if ($i >= $count) {
                    throw new ParseException(\sprintf('INCLUDE requires a filename in %s', $sourcePath));
                }

                $arg = $tokens[$i];
                ++$i;
                self::loadFile(self::resolveInclude($arg, $sourcePath), $client, $depth + 1);
                continue;
            }

            if (!str_starts_with($token, '--')) {
                self::notice(\sprintf('libmemcached config: skipping unknown token "%s" in %s', $token, $sourcePath));
                continue;
            }

            // Strip the leading `--`, split off the `=value` (if any), and
            // normalise the directive name to dashes only — libmemcached's
            // own parser accepts both `--REMOVE-FAILED-SERVERS` and the
            // legacy `--REMOVE_FAILED_SERVERS` spelling.
            $body = substr($token, 2);
            $equals = strpos($body, '=');
            if (false === $equals) {
                $name = $body;
                $value = null;
            } else {
                $name = substr($body, 0, $equals);
                $value = substr($body, $equals + 1);
            }

            $directive = strtoupper(str_replace('_', '-', $name));

            self::applyDirective($directive, $value, $client, $sourcePath, $depth);
        }
    }

    /**
     * @param AbstractCacheClient<ClientCoreState> $client
     *
     * @throws ParseException
     */
    private static function applyDirective(string $directive, ?string $value, AbstractCacheClient $client, string $sourcePath, int $depth): void
    {
        $hasValue = null !== $value;

        if ('CONFIGURE-FILE' === $directive) {
            if (!$hasValue) {
                throw new ParseException('--CONFIGURE-FILE requires a value');
            }

            // Per libmemcached: "by using a configuration file libmemcached
            // will reset memcached_st based on information only contained in
            // the file" — so the host pool and behaviours get wiped before
            // the nested file is processed.
            $client->resetServerList();
            $client->setOptions(ClientOptions::defaults());
            self::loadFile(self::resolveInclude($value, $sourcePath), $client, $depth + 1);

            return;
        }

        if ('SERVER' === $directive) {
            if (!$hasValue) {
                throw new ParseException('--SERVER requires a host[:port][/?weight] value');
            }

            self::applyServer($value, $client);

            return;
        }

        if ('SOCKET' === $directive) {
            if (!$hasValue) {
                throw new ParseException('--SOCKET requires a path value');
            }

            self::applySocket($value, $client);

            return;
        }

        if ('NAMESPACE' === $directive) {
            if (!$hasValue) {
                throw new ParseException('--NAMESPACE requires a value');
            }

            self::dispatchOption($client, MemcachedConstants::OPT_PREFIX_KEY, $value, $directive, $sourcePath);

            return;
        }

        if ('HASH' === $directive) {
            if (!$hasValue) {
                throw new ParseException('--HASH requires a name');
            }

            $hash = self::resolveHashName($value);
            if (null === $hash) {
                self::notice(\sprintf('libmemcached config: unknown hash "%s" in %s', $value, $sourcePath));

                return;
            }

            self::dispatchOption($client, MemcachedConstants::OPT_HASH, $hash, $directive, $sourcePath);

            return;
        }

        if ('DISTRIBUTION' === $directive) {
            if (!$hasValue) {
                throw new ParseException('--DISTRIBUTION requires a name');
            }

            self::applyDistribution($value, $client, $sourcePath);

            return;
        }

        if (isset(self::booleanDirectives()[$directive])) {
            self::dispatchOption($client, self::booleanDirectives()[$directive], true, $directive, $sourcePath);

            return;
        }

        if (isset(self::nonNegativeIntDirectives()[$directive])) {
            if (!$hasValue) {
                throw new ParseException(\sprintf('--%s requires an integer value', $directive));
            }

            $coerced = ClientOptions::intValue($value);
            if (null === $coerced || $coerced < 0) {
                throw new ParseException(\sprintf('--%s requires a non-negative integer (got "%s")', $directive, $value));
            }

            self::dispatchOption($client, self::nonNegativeIntDirectives()[$directive], $coerced, $directive, $sourcePath);

            return;
        }

        if ('POOL-MIN' === $directive || 'POOL-MAX' === $directive) {
            // PureCache has no in-process libmemcached pool — accept silently.
            return;
        }

        self::notice(\sprintf('libmemcached config: unsupported directive --%s in %s', $directive, $sourcePath));
    }

    /**
     * @param AbstractCacheClient<ClientCoreState> $client
     */
    private static function applyServer(string $value, AbstractCacheClient $client): void
    {
        [$host, $port, $weight] = self::splitHostPortWeight($value);
        if ('' === $host) {
            throw new ParseException(\sprintf('invalid --SERVER value "%s"', $value));
        }

        $client->addServer($host, $port, $weight);
    }

    /**
     * @param AbstractCacheClient<ClientCoreState> $client
     */
    private static function applySocket(string $value, AbstractCacheClient $client): void
    {
        $weight = 0;
        $path = $value;
        if (false !== ($slash = strpos($value, '/?'))) {
            $path = substr($value, 0, $slash);
            $weightRaw = substr($value, $slash + 2);
            $coerced = ClientOptions::intValue($weightRaw);
            if (null === $coerced || $coerced < 0) {
                throw new ParseException(\sprintf('--SOCKET weight must be non-negative integer (got "%s")', $weightRaw));
            }

            $weight = $coerced;
        }

        if ('' === $path) {
            throw new ParseException('--SOCKET requires a non-empty path');
        }

        // {@see \PureCache\Memcached\Internal\StreamConnection::connect()}
        // already detects a leading '/' as a unix socket — port is unused
        // there, so 0 is the safest passthrough.
        $client->addServer($path, 0, $weight);
    }

    /**
     * @return array{0:string,1:int,2:int}
     */
    private static function splitHostPortWeight(string $value): array
    {
        $host = $value;
        $port = 0;
        $weight = 0;

        if (false !== ($slash = strpos($value, '/?'))) {
            $host = substr($value, 0, $slash);
            $weightRaw = substr($value, $slash + 2);
            $coerced = ClientOptions::intValue($weightRaw);
            if (null === $coerced || $coerced < 0) {
                throw new ParseException(\sprintf('--SERVER weight must be non-negative integer (got "%s")', $weightRaw));
            }

            $weight = $coerced;
        }

        if ('[' === ($host[0] ?? '') && false !== ($rb = strrpos($host, ']'))) {
            // IPv6 literal: "[::1]:11211"
            $portPart = substr($host, $rb + 1);
            $host = substr($host, 1, $rb - 1);
            if (str_starts_with($portPart, ':')) {
                $coerced = ClientOptions::intValue(substr($portPart, 1));
                if (null === $coerced || $coerced < 0 || $coerced > 65535) {
                    throw new ParseException(\sprintf('--SERVER port out of range (got "%s")', $portPart));
                }

                $port = $coerced;
            }

            return [$host, $port, $weight];
        }

        if (false !== ($colon = strrpos($host, ':'))) {
            $portRaw = substr($host, $colon + 1);
            $coerced = ClientOptions::intValue($portRaw);
            if (null !== $coerced && $coerced >= 0 && $coerced <= 65535) {
                $port = $coerced;
                $host = substr($host, 0, $colon);
            }
        }

        return [$host, $port, $weight];
    }

    /**
     * @param AbstractCacheClient<ClientCoreState> $client
     */
    private static function applyDistribution(string $name, AbstractCacheClient $client, string $sourcePath): void
    {
        $normalised = strtoupper(str_replace('-', '_', $name));
        switch ($normalised) {
            case 'MODULA':
                $client->setOption(MemcachedConstants::OPT_DISTRIBUTION, MemcachedConstants::DISTRIBUTION_MODULA);

                return;
            case 'CONSISTENT':
            case 'CONSISTENT_KETAMA':
            case 'KETAMA':
                $client->setOption(MemcachedConstants::OPT_DISTRIBUTION, MemcachedConstants::DISTRIBUTION_CONSISTENT);

                return;
            case 'CONSISTENT_KETAMA_SPY':
            case 'KETAMA_WEIGHTED':
                // libmemcached has KETAMA_WEIGHTED as a separate value, but
                // PureCache's consistent ring already weights by the
                // per-server `weight` field, so funneling the two into one
                // distribution is functionally equivalent.
                $client->setOption(MemcachedConstants::OPT_DISTRIBUTION, MemcachedConstants::DISTRIBUTION_CONSISTENT);
                $client->setOption(MemcachedConstants::OPT_LIBKETAMA_COMPATIBLE, true);

                return;
            case 'VIRTUAL_BUCKET':
                $client->setOption(MemcachedConstants::OPT_DISTRIBUTION, MemcachedConstants::DISTRIBUTION_VIRTUAL_BUCKET);

                return;
            case 'RANDOM':
                self::notice(\sprintf('libmemcached config: DISTRIBUTION=random is not supported in PureCache (file %s)', $sourcePath));

                return;
            default:
                self::notice(\sprintf('libmemcached config: unknown distribution "%s" in %s', $name, $sourcePath));
        }
    }

    private static function resolveHashName(string $name): ?int
    {
        $key = strtoupper(str_replace('-', '_', $name));

        return match ($key) {
            'DEFAULT' => MemcachedConstants::HASH_DEFAULT,
            'MD5' => MemcachedConstants::HASH_MD5,
            'CRC' => MemcachedConstants::HASH_CRC,
            'FNV1_64' => MemcachedConstants::HASH_FNV1_64,
            'FNV1A_64' => MemcachedConstants::HASH_FNV1A_64,
            'FNV1_32' => MemcachedConstants::HASH_FNV1_32,
            'FNV1A_32' => MemcachedConstants::HASH_FNV1A_32,
            'HSIEH' => MemcachedConstants::HASH_HSIEH,
            'MURMUR' => MemcachedConstants::HASH_MURMUR,
            // libmemcached recognises JENKINS but neither PECL nor PureCache
            // ship the hashkit jenkins kernel; fold into DEFAULT so an old
            // config doesn't reject outright.
            'JENKINS' => MemcachedConstants::HASH_DEFAULT,
            default => null,
        };
    }

    /**
     * Boolean flag → {@code OPT_*} mapping. Directive names already
     * normalised to upper-case with dashes (so `--REMOVE_FAILED_SERVERS`
     * lands here as `REMOVE-FAILED-SERVERS`).
     *
     * @return array<string, int>
     */
    private static function booleanDirectives(): array
    {
        return [
            'VERIFY-KEY' => MemcachedConstants::OPT_VERIFY_KEY,
            'SUPPORT-CAS' => MemcachedConstants::OPT_SUPPORT_CAS,
            'NOREPLY' => MemcachedConstants::OPT_NOREPLY,
            'BUFFER-REQUESTS' => MemcachedConstants::OPT_BUFFER_WRITES,
            'HASH-WITH-NAMESPACE' => MemcachedConstants::OPT_HASH_WITH_PREFIX_KEY,
            'REMOVE-FAILED-SERVERS' => MemcachedConstants::OPT_REMOVE_FAILED_SERVERS,
            'SORT-HOSTS' => MemcachedConstants::OPT_SORT_HOSTS,
            'RANDOMIZE-REPLICA-READ' => MemcachedConstants::OPT_RANDOMIZE_REPLICA_READ,
            'BINARY-PROTOCOL' => MemcachedConstants::OPT_BINARY_PROTOCOL,
            'USE-UDP' => MemcachedConstants::OPT_USE_UDP,
            'TCP-NODELAY' => MemcachedConstants::OPT_TCP_NODELAY,
            'TCP-KEEPALIVE' => MemcachedConstants::OPT_TCP_KEEPALIVE,
            'AUTO-EJECT-HOSTS' => MemcachedConstants::OPT_AUTO_EJECT_HOSTS,
            'LIBKETAMA-COMPATIBLE' => MemcachedConstants::OPT_LIBKETAMA_COMPATIBLE,
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function nonNegativeIntDirectives(): array
    {
        return [
            'CONNECT-TIMEOUT' => MemcachedConstants::OPT_CONNECT_TIMEOUT,
            'RCV-TIMEOUT' => MemcachedConstants::OPT_RECV_TIMEOUT,
            'SND-TIMEOUT' => MemcachedConstants::OPT_SEND_TIMEOUT,
            'POLL-TIMEOUT' => MemcachedConstants::OPT_POLL_TIMEOUT,
            'RETRY-TIMEOUT' => MemcachedConstants::OPT_RETRY_TIMEOUT,
            'SERVER-FAILURE-LIMIT' => MemcachedConstants::OPT_SERVER_FAILURE_LIMIT,
            'SOCKET-RECV-SIZE' => MemcachedConstants::OPT_SOCKET_RECV_SIZE,
            'SOCKET-SEND-SIZE' => MemcachedConstants::OPT_SOCKET_SEND_SIZE,
            'IO-BYTES-WATERMARK' => MemcachedConstants::OPT_IO_BYTES_WATERMARK,
            'IO-MSG-WATERMARK' => MemcachedConstants::OPT_IO_MSG_WATERMARK,
            'IO-KEY-PREFETCH' => MemcachedConstants::OPT_IO_KEY_PREFETCH,
            'NUMBER-OF-REPLICAS' => MemcachedConstants::OPT_NUMBER_OF_REPLICAS,
            'TCP-KEEPIDLE' => MemcachedConstants::OPT_TCP_KEEPIDLE,
            'DEAD-TIMEOUT' => MemcachedConstants::OPT_DEAD_TIMEOUT,
        ];
    }

    /**
     * @param AbstractCacheClient<ClientCoreState> $client
     */
    private static function dispatchOption(AbstractCacheClient $client, int $option, mixed $value, string $directive, string $sourcePath): void
    {
        $ok = $client->setOption($option, $value);
        if ($ok) {
            return;
        }

        // Surface the failure but do not abort the whole file — libmemcached
        // itself silently downgrades behaviours its build can't honour, so a
        // shared config file remains portable across leaner consumers.
        self::notice(\sprintf(
            'libmemcached config: --%s rejected by backend (code=%d) in %s',
            $directive,
            $client->getResultCode(),
            $sourcePath,
        ));
    }

    private static function resolveInclude(string $included, string $sourcePath): string
    {
        if ('' === $included) {
            throw new ParseException(\sprintf('empty include path in %s', $sourcePath));
        }

        if (\DIRECTORY_SEPARATOR === $included[0] || 1 === preg_match('#^[a-zA-Z]:[\\\\/]#', $included)) {
            return $included;
        }

        return \dirname($sourcePath).\DIRECTORY_SEPARATOR.$included;
    }

    private static function notice(string $message): void
    {
        trigger_error($message, \E_USER_NOTICE);
    }
}
