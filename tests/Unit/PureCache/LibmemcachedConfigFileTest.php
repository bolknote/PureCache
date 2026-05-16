<?php

declare(strict_types=1);

namespace PureCache\Tests\Unit\PureCache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PureCache\Memcached\MemcachedClient;
use PureCache\MemcachedConstants;

/**
 * Branch-level coverage for the libmemcached configuration-file parser
 * routed through {@code OPT_LOAD_FROM_FILE}.
 *
 * {@see \PureCache\Tests\Unit\MemcachedClientStateTest} pins the client-side
 * contract (return codes, stored path, basic happy paths); this suite drills
 * into the directive matrix —- every hash kernel, distribution variant,
 * boolean knob, integer knob, edge case of the tokenizer — so a refactor of
 * {@code LibmemcachedConfigFile} that quietly drops a branch breaks here
 * before parity tests against the real C extension catch it.
 */
final class LibmemcachedConfigFileTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        $this->tempFiles = [];
        parent::tearDown();
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function hashAliases(): array
    {
        return [
            'default' => ['default', MemcachedConstants::HASH_DEFAULT],
            'md5_lower' => ['md5', MemcachedConstants::HASH_MD5],
            'md5_upper' => ['MD5', MemcachedConstants::HASH_MD5],
            'crc' => ['crc', MemcachedConstants::HASH_CRC],
            'fnv1_64' => ['fnv1_64', MemcachedConstants::HASH_FNV1_64],
            'fnv1a_64' => ['fnv1a_64', MemcachedConstants::HASH_FNV1A_64],
            'fnv1_32' => ['fnv1_32', MemcachedConstants::HASH_FNV1_32],
            'fnv1a_32' => ['fnv1a_32', MemcachedConstants::HASH_FNV1A_32],
            'hsieh' => ['hsieh', MemcachedConstants::HASH_HSIEH],
            'murmur' => ['murmur', MemcachedConstants::HASH_MURMUR],
            'fnv1a_64_dashed' => ['fnv1a-64', MemcachedConstants::HASH_FNV1A_64],
            // libmemcached recognises JENKINS for parser-level back-compat;
            // PureCache (and PECL) fold it into DEFAULT because the hashkit
            // jenkins kernel isn't shipped.
            'jenkins_fallback' => ['jenkins', MemcachedConstants::HASH_DEFAULT],
        ];
    }

    #[DataProvider('hashAliases')]
    public function testHashDirectiveRecognisesEveryLibmemcachedAlias(string $alias, int $expected): void
    {
        $client = $this->loadConfig(\sprintf('--HASH=%s%s', $alias, \PHP_EOL));

        self::assertSame($expected, $client->getOption(MemcachedClient::OPT_HASH));
    }

    public function testHashDirectiveSurfacesUnknownAliasAsNoticeWithoutAborting(): void
    {
        $client = new MemcachedClient();
        $captured = $this->captureNotices(function () use ($client): void {
            self::assertTrue($client->setOption(
                MemcachedClient::OPT_LOAD_FROM_FILE,
                $this->writeConfig("--HASH=quantum\n--SERVER=h:11211\n"),
            ));
        });

        self::assertSame(MemcachedClient::HASH_DEFAULT, $client->getOption(MemcachedClient::OPT_HASH));
        self::assertSame([['host' => 'h', 'port' => 11211, 'type' => 'TCP', 'weight' => 0]], $client->getServerList());
        self::assertCount(1, $captured);
        self::assertStringContainsString('unknown hash "quantum"', $captured[0]);
    }

    /**
     * @return array<string, array{string, int, bool}>
     */
    public static function distributionAliases(): array
    {
        return [
            'modula' => ['modula', MemcachedConstants::DISTRIBUTION_MODULA, false],
            'consistent' => ['consistent', MemcachedConstants::DISTRIBUTION_CONSISTENT, false],
            'consistent_ketama' => ['consistent_ketama', MemcachedConstants::DISTRIBUTION_CONSISTENT, false],
            'ketama' => ['ketama', MemcachedConstants::DISTRIBUTION_CONSISTENT, false],
            // Spy/weighted variants funnel into CONSISTENT + LIBKETAMA_COMPATIBLE.
            'ketama_spy' => ['consistent_ketama_spy', MemcachedConstants::DISTRIBUTION_CONSISTENT, true],
            'ketama_weighted' => ['ketama_weighted', MemcachedConstants::DISTRIBUTION_CONSISTENT, true],
            'virtual_bucket' => ['virtual_bucket', MemcachedConstants::DISTRIBUTION_VIRTUAL_BUCKET, false],
        ];
    }

    #[DataProvider('distributionAliases')]
    public function testDistributionDirectiveCoversEveryLibmemcachedVariant(string $alias, int $expectedDistribution, bool $expectsLibketamaCompat): void
    {
        $client = $this->loadConfig(\sprintf('--DISTRIBUTION=%s%s', $alias, \PHP_EOL));

        self::assertSame($expectedDistribution, $client->getOption(MemcachedClient::OPT_DISTRIBUTION));
        self::assertSame(
            $expectsLibketamaCompat ? 1 : 0,
            $client->getOption(MemcachedClient::OPT_LIBKETAMA_COMPATIBLE),
        );
    }

    public function testDistributionRandomTriggersNoticeWithoutMutation(): void
    {
        $client = new MemcachedClient();
        $defaultDistribution = $client->getOption(MemcachedClient::OPT_DISTRIBUTION);

        $captured = $this->captureNotices(function () use ($client): void {
            self::assertTrue($client->setOption(
                MemcachedClient::OPT_LOAD_FROM_FILE,
                $this->writeConfig("--DISTRIBUTION=random\n"),
            ));
        });

        self::assertSame($defaultDistribution, $client->getOption(MemcachedClient::OPT_DISTRIBUTION));
        self::assertCount(1, $captured);
        self::assertStringContainsString('DISTRIBUTION=random is not supported', $captured[0]);
    }

    public function testDistributionUnknownAliasTriggersNoticeWithoutMutation(): void
    {
        $client = new MemcachedClient();
        $defaultDistribution = $client->getOption(MemcachedClient::OPT_DISTRIBUTION);

        $captured = $this->captureNotices(function () use ($client): void {
            self::assertTrue($client->setOption(
                MemcachedClient::OPT_LOAD_FROM_FILE,
                $this->writeConfig("--DISTRIBUTION=quantum\n"),
            ));
        });

        self::assertSame($defaultDistribution, $client->getOption(MemcachedClient::OPT_DISTRIBUTION));
        self::assertCount(1, $captured);
        self::assertStringContainsString('unknown distribution "quantum"', $captured[0]);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function booleanDirectives(): array
    {
        return [
            'verify_key' => ['--VERIFY-KEY', MemcachedConstants::OPT_VERIFY_KEY],
            'support_cas' => ['--SUPPORT-CAS', MemcachedConstants::OPT_SUPPORT_CAS],
            'noreply' => ['--NOREPLY', MemcachedConstants::OPT_NOREPLY],
            'buffer_requests' => ['--BUFFER-REQUESTS', MemcachedConstants::OPT_BUFFER_WRITES],
            'hash_with_namespace' => ['--HASH-WITH-NAMESPACE', MemcachedConstants::OPT_HASH_WITH_PREFIX_KEY],
            'remove_failed_servers' => ['--REMOVE-FAILED-SERVERS', MemcachedConstants::OPT_REMOVE_FAILED_SERVERS],
            'sort_hosts' => ['--SORT-HOSTS', MemcachedConstants::OPT_SORT_HOSTS],
            'randomize_replica_read' => ['--RANDOMIZE-REPLICA-READ', MemcachedConstants::OPT_RANDOMIZE_REPLICA_READ],
            'tcp_nodelay' => ['--TCP-NODELAY', MemcachedConstants::OPT_TCP_NODELAY],
            'tcp_keepalive' => ['--TCP-KEEPALIVE', MemcachedConstants::OPT_TCP_KEEPALIVE],
            'libketama_compatible' => ['--LIBKETAMA-COMPATIBLE', MemcachedConstants::OPT_LIBKETAMA_COMPATIBLE],
            // Underscore-spelled form lands on the same target — libmemcached
            // accepts both `--REMOVE_FAILED_SERVERS` and `--REMOVE-FAILED-SERVERS`,
            // we normalise to dashes before dispatch.
            'remove_failed_servers_underscored' => ['--REMOVE_FAILED_SERVERS', MemcachedConstants::OPT_REMOVE_FAILED_SERVERS],
        ];
    }

    /**
     * The directive matrix maps to {@code OPT_*} dials that PureCache stores
     * with mixed shapes — some come back as {@code 1} (those listed in
     * {@see \PureCache\AbstractCacheClient::optionReturnsIntegerBoolean()})
     * and some as raw PHP {@code true} (failover/host-management toggles
     * that never went through the PECL int-boolean projection). The
     * directive-level invariant we care about is "the dial landed truthy",
     * not the storage shape — anything stricter is a duplicate of
     * {@see ClientOptionsTest}'s integer-boolean coverage.
     */
    #[DataProvider('booleanDirectives')]
    public function testBooleanDirectivesFlipTargetOptionToTruthy(string $directive, int $option): void
    {
        $client = $this->loadConfig($directive.\PHP_EOL);

        $value = $client->getOption($option);
        self::assertNotNull($value, \sprintf('option %d not stored', $option));
        self::assertTrue((bool) $value, \sprintf('option %d not truthy after %s', $option, $directive));
    }

    public function testAutoEjectHostsIsMappedButDowngradedByBackendWithNotice(): void
    {
        // AUTO_EJECT_HOSTS is recognised by the libmemcached DSL so a
        // shared config file parses, but PureCache's applier doesn't wire
        // the toggle to anything (the host pool re-routes via the failure
        // tracker regardless), so the underlying setOption() reports
        // RES_INVALID_ARGUMENTS and the parser surfaces a notice.
        $client = new MemcachedClient();
        $captured = $this->captureNotices(function () use ($client): void {
            self::assertTrue($client->setOption(
                MemcachedClient::OPT_LOAD_FROM_FILE,
                $this->writeConfig("--AUTO-EJECT-HOSTS\n--SERVER=h:11211\n"),
            ));
        });

        self::assertNull($client->getOption(MemcachedClient::OPT_AUTO_EJECT_HOSTS));
        self::assertSame([['host' => 'h', 'port' => 11211, 'type' => 'TCP', 'weight' => 0]], $client->getServerList());
        self::assertCount(1, $captured);
        self::assertStringContainsString('--AUTO-EJECT-HOSTS rejected by backend', $captured[0]);
    }

    public function testBinaryAndUseUdpDirectivesAreSurfacedAsBackendDowngrades(): void
    {
        $client = new MemcachedClient();
        $captured = $this->captureNotices(function () use ($client): void {
            self::assertTrue($client->setOption(
                MemcachedClient::OPT_LOAD_FROM_FILE,
                $this->writeConfig("--BINARY-PROTOCOL\n--USE-UDP\n--SERVER=h:11211\n"),
            ));
        });

        // The server list still landed — libmemcached's policy is to keep
        // processing past directives the build can't honour, and we mirror
        // that with a notice rather than an abort.
        self::assertSame([['host' => 'h', 'port' => 11211, 'type' => 'TCP', 'weight' => 0]], $client->getServerList());
        self::assertGreaterThanOrEqual(2, \count($captured));
        $joined = implode("\n", $captured);
        self::assertStringContainsString('--BINARY-PROTOCOL', $joined);
        self::assertStringContainsString('--USE-UDP', $joined);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function nonNegativeIntDirectives(): array
    {
        return [
            'connect_timeout' => ['--CONNECT-TIMEOUT', MemcachedConstants::OPT_CONNECT_TIMEOUT],
            'rcv_timeout' => ['--RCV-TIMEOUT', MemcachedConstants::OPT_RECV_TIMEOUT],
            'snd_timeout' => ['--SND-TIMEOUT', MemcachedConstants::OPT_SEND_TIMEOUT],
            'poll_timeout' => ['--POLL-TIMEOUT', MemcachedConstants::OPT_POLL_TIMEOUT],
            'retry_timeout' => ['--RETRY-TIMEOUT', MemcachedConstants::OPT_RETRY_TIMEOUT],
            'server_failure_limit' => ['--SERVER-FAILURE-LIMIT', MemcachedConstants::OPT_SERVER_FAILURE_LIMIT],
            'socket_recv_size' => ['--SOCKET-RECV-SIZE', MemcachedConstants::OPT_SOCKET_RECV_SIZE],
            'socket_send_size' => ['--SOCKET-SEND-SIZE', MemcachedConstants::OPT_SOCKET_SEND_SIZE],
            'io_bytes_watermark' => ['--IO-BYTES-WATERMARK', MemcachedConstants::OPT_IO_BYTES_WATERMARK],
            'io_msg_watermark' => ['--IO-MSG-WATERMARK', MemcachedConstants::OPT_IO_MSG_WATERMARK],
            'io_key_prefetch' => ['--IO-KEY-PREFETCH', MemcachedConstants::OPT_IO_KEY_PREFETCH],
            'number_of_replicas' => ['--NUMBER-OF-REPLICAS', MemcachedConstants::OPT_NUMBER_OF_REPLICAS],
            'tcp_keepidle' => ['--TCP-KEEPIDLE', MemcachedConstants::OPT_TCP_KEEPIDLE],
            'dead_timeout' => ['--DEAD-TIMEOUT', MemcachedConstants::OPT_DEAD_TIMEOUT],
        ];
    }

    #[DataProvider('nonNegativeIntDirectives')]
    public function testNonNegativeIntDirectivesAreStoredVerbatim(string $directive, int $option): void
    {
        $client = $this->loadConfig($directive.'=42
');

        self::assertSame(42, $client->getOption($option));
    }

    public function testNegativeIntegerForKnownDirectiveFailsTheWholeLoad(): void
    {
        $client = new MemcachedClient();

        self::assertFalse($client->setOption(
            MemcachedClient::OPT_LOAD_FROM_FILE,
            $this->writeConfig("--POLL-TIMEOUT=-1\n"),
        ));
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }

    public function testIntegerDirectiveWithoutValueFailsTheWholeLoad(): void
    {
        $client = new MemcachedClient();

        self::assertFalse($client->setOption(
            MemcachedClient::OPT_LOAD_FROM_FILE,
            $this->writeConfig("--POLL-TIMEOUT\n"),
        ));
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }

    public function testServerDirectiveParsesIPv6LiteralAndWeight(): void
    {
        // IPv6 hosts come through verbatim (without surrounding brackets);
        // the port=0 branch funnels into addServer()'s defaultPort() — for
        // Memcached that's the canonical 11211, which is what a bare
        // `[2001:db8::1]` libmemcached server entry resolves to as well.
        $client = $this->loadConfig("--SERVER=[::1]:11315/?5\n--SERVER=[2001:db8::1]\n");

        self::assertSame([
            ['host' => '::1', 'port' => 11315, 'type' => 'TCP', 'weight' => 5],
            ['host' => '2001:db8::1', 'port' => 11211, 'type' => 'TCP', 'weight' => 0],
        ], $client->getServerList());
    }

    public function testServerDirectiveWithBareHostnameSubstitutesDefaultPort(): void
    {
        $client = $this->loadConfig("--SERVER=cache.internal\n");

        // No `:port`, no `/?weight` — addServer() substitutes defaultPort()
        // (11211 for Memcached), matching the libmemcached default.
        self::assertSame(
            [['host' => 'cache.internal', 'port' => 11211, 'type' => 'TCP', 'weight' => 0]],
            $client->getServerList(),
        );
    }

    public function testSocketDirectiveRegistersUnixSocketEndpoint(): void
    {
        $client = $this->loadConfig("--SOCKET=/var/run/memcached.sock/?2\n");

        $list = $client->getServerList();
        self::assertCount(1, $list);
        self::assertSame('/var/run/memcached.sock', $list[0]['host']);
        // The unix-socket path is treated as the host; addServer() then
        // fills port with defaultPort() (port is unused at connect time
        // for paths beginning with '/' — see StreamConnection::connect()).
        self::assertSame(11211, $list[0]['port']);
        self::assertSame(2, $list[0]['weight']);
    }

    public function testNamespaceDirectiveAcceptsQuotedAsciiValue(): void
    {
        // The quotes are stripped by the tokenizer; we use an ASCII-safe
        // body because OPT_PREFIX_KEY is validated by KeyFormatter, which
        // rejects whitespace and control characters in strict-key mode.
        $client = $this->loadConfig("--NAMESPACE=\"app:prefix.\"\n");

        self::assertSame('app:prefix.', $client->getOption(MemcachedClient::OPT_PREFIX_KEY));
    }

    public function testTokenizerSplicesQuotedSpanIntoSurroundingDirectiveValue(): void
    {
        // The directive `--SOCKET="/var/run/m.sock"/?3` exercises the
        // mid-token quoted-span branch: the leading `--SOCKET=`, the
        // quoted middle, and the trailing `/?3` all collapse into a
        // single token, with quotes stripped.
        $client = $this->loadConfig("--SOCKET=\"/var/run/m.sock\"/?3\n");

        $list = $client->getServerList();
        self::assertCount(1, $list);
        self::assertSame('/var/run/m.sock', $list[0]['host']);
        self::assertSame(3, $list[0]['weight']);
    }

    public function testConfigureFileDirectiveResetsBeforeLoadingNestedFile(): void
    {
        $nested = $this->writeConfig("--SERVER=nested.host:11212\n--HASH=md5\n");
        $client = new MemcachedClient();
        // Pre-seed state that --CONFIGURE-FILE must wipe.
        self::assertTrue($client->setOption(MemcachedClient::OPT_PREFIX_KEY, 'before_'));
        self::assertTrue($client->addServer('pre.host', 11211));

        self::assertTrue($client->setOption(
            MemcachedClient::OPT_LOAD_FROM_FILE,
            $this->writeConfig("--CONFIGURE-FILE=\"{$nested}\"\n"),
        ));

        self::assertSame(
            [['host' => 'nested.host', 'port' => 11212, 'type' => 'TCP', 'weight' => 0]],
            $client->getServerList(),
        );
        self::assertSame(MemcachedClient::HASH_MD5, $client->getOption(MemcachedClient::OPT_HASH));
        // Defaults were restored before the nested file ran, so any prior
        // OPT_PREFIX_KEY is gone (the nested file didn't set its own).
        self::assertSame('', $client->getOption(MemcachedClient::OPT_PREFIX_KEY));
    }

    public function testIncludeDirectiveIsAdditiveAndResolvesRelativePaths(): void
    {
        $inner = $this->writeConfig("--SERVER=inner.host:11220\n");
        $outerPath = $this->reserveTempPath();
        // Use a relative include to exercise dirname() resolution.
        file_put_contents($outerPath, "--SERVER=outer.host:11221\nINCLUDE \"".basename($inner)."\"\n");

        $client = new MemcachedClient();
        self::assertTrue($client->setOption(MemcachedClient::OPT_LOAD_FROM_FILE, $outerPath));

        self::assertSame(['outer.host', 'inner.host'], array_map(
            static fn (array $s): string => $s['host'],
            $client->getServerList(),
        ));
    }

    public function testIncludeDepthLimitAbortsTheLoad(): void
    {
        // MAX_INCLUDE_DEPTH=16 — chain[15] includes chain[16], which is
        // loaded at depth=16 and trips the guard. 17 files total.
        $paths = [];
        for ($i = 0; $i < 17; ++$i) {
            $paths[] = $this->reserveTempPath();
        }

        for ($i = 0; $i < 17; ++$i) {
            $next = $paths[$i + 1] ?? null;
            $body = null === $next ? "--SERVER=leaf.host:11211\n" : "INCLUDE \"{$next}\"\n";
            file_put_contents($paths[$i], $body);
        }

        $client = new MemcachedClient();
        self::assertFalse($client->setOption(MemcachedClient::OPT_LOAD_FROM_FILE, $paths[0]));
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }

    public function testUnsupportedDirectiveTriggersNoticeButLoadStillSucceeds(): void
    {
        $client = new MemcachedClient();
        $captured = $this->captureNotices(function () use ($client): void {
            self::assertTrue($client->setOption(
                MemcachedClient::OPT_LOAD_FROM_FILE,
                $this->writeConfig("--UNHEARD-OF-KNOB=42\n--SERVER=h:11211\n"),
            ));
        });

        self::assertSame([['host' => 'h', 'port' => 11211, 'type' => 'TCP', 'weight' => 0]], $client->getServerList());
        self::assertCount(1, $captured);
        self::assertStringContainsString('unsupported directive --UNHEARD-OF-KNOB', $captured[0]);
    }

    public function testPoolDirectivesAreAcceptedSilentlyForPeclParity(): void
    {
        $client = new MemcachedClient();
        $captured = $this->captureNotices(function () use ($client): void {
            self::assertTrue($client->setOption(
                MemcachedClient::OPT_LOAD_FROM_FILE,
                // POOL-MIN/POOL-MAX are libmemcached_st-internal pool
                // controls; PureCache has no in-process pool, so they must
                // be accepted without surfacing a notice (otherwise every
                // libmemcached config triggers spam).
                $this->writeConfig("--POOL-MIN=1\n--POOL-MAX=8\n--SERVER=h:11211\n"),
            ));
        });

        self::assertSame([['host' => 'h', 'port' => 11211, 'type' => 'TCP', 'weight' => 0]], $client->getServerList());
        self::assertSame([], $captured);
    }

    public function testCommentsAndBlankLinesAndBomAreIgnored(): void
    {
        $body = "\xEF\xBB\xBF# header comment\n\n  # indented comment with --SERVER=ignored:11211 inside\n--SERVER=real.host:11211   # trailing comment\n\n--HASH=md5\n";
        $client = $this->loadConfig($body);

        self::assertSame(
            [['host' => 'real.host', 'port' => 11211, 'type' => 'TCP', 'weight' => 0]],
            $client->getServerList(),
        );
        self::assertSame(MemcachedClient::HASH_MD5, $client->getOption(MemcachedClient::OPT_HASH));
    }

    public function testEndDirectiveStopsParsingBeforeRemainingTokens(): void
    {
        $client = $this->loadConfig("--SERVER=before-end:11211\nEND\n--SERVER=after-end:11212\n");

        self::assertSame(
            [['host' => 'before-end', 'port' => 11211, 'type' => 'TCP', 'weight' => 0]],
            $client->getServerList(),
        );
    }

    public function testBareTokenWithoutDoubleDashTriggersNotice(): void
    {
        $client = new MemcachedClient();
        $captured = $this->captureNotices(function () use ($client): void {
            self::assertTrue($client->setOption(
                MemcachedClient::OPT_LOAD_FROM_FILE,
                $this->writeConfig("oops_garbage\n--SERVER=h:11211\n"),
            ));
        });

        self::assertSame([['host' => 'h', 'port' => 11211, 'type' => 'TCP', 'weight' => 0]], $client->getServerList());
        self::assertCount(1, $captured);
        self::assertStringContainsString('skipping unknown token "oops_garbage"', $captured[0]);
    }

    public function testSocketDirectiveAddsUnixServer(): void
    {
        $path = $this->reserveTempPath();
        $socketPath = sys_get_temp_dir().'/purecache-sock-'.uniqid('', true);
        file_put_contents($path, '--SOCKET="'.$socketPath.'"/?2'."\n");

        $client = new MemcachedClient();
        self::assertTrue($client->setOption(MemcachedClient::OPT_LOAD_FROM_FILE, $path));
        $servers = $client->getServerList();
        self::assertCount(1, $servers);
        self::assertSame($socketPath, $servers[0]['host']);
        self::assertSame(2, $servers[0]['weight']);
    }

    public function testErrorDirectiveStopsParsing(): void
    {
        $client = new MemcachedClient();
        self::assertFalse($client->setOption(
            MemcachedClient::OPT_LOAD_FROM_FILE,
            $this->writeConfig("ERROR\n--SERVER=never:11211\n"),
        ));
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }

    public function testFileExceedingSizeLimitIsRejected(): void
    {
        $path = $this->reserveTempPath();
        // 1 MiB + 1 byte of harmless padding.
        $payload = "--SERVER=h:11211\n".str_repeat('# pad', intdiv(1 << 20, 5) + 1)."\n";
        file_put_contents($path, $payload);
        self::assertGreaterThan(1 << 20, filesize($path));

        $client = new MemcachedClient();
        self::assertFalse($client->setOption(MemcachedClient::OPT_LOAD_FROM_FILE, $path));
        self::assertSame(MemcachedClient::RES_INVALID_ARGUMENTS, $client->getResultCode());
    }

    private function loadConfig(string $body): MemcachedClient
    {
        $client = new MemcachedClient();
        self::assertTrue($client->setOption(MemcachedClient::OPT_LOAD_FROM_FILE, $this->writeConfig($body)));
        self::assertSame(MemcachedClient::RES_SUCCESS, $client->getResultCode());

        return $client;
    }

    private function writeConfig(string $body): string
    {
        $path = $this->reserveTempPath();
        file_put_contents($path, $body);

        return $path;
    }

    private function reserveTempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'purecache_lm_test_');
        self::assertIsString($path);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Capture {@code E_USER_NOTICE} emitted by the parser without leaking
     * them to PHPUnit's "no error during test" convertor.
     *
     * @return list<string>
     */
    private function captureNotices(callable $fn): array
    {
        /** @var list<string> $captured */
        $captured = [];
        $previous = set_error_handler(static function (int $errno, string $message) use (&$captured): bool {
            if (\E_USER_NOTICE === $errno) {
                $captured[] = $message;

                return true;
            }

            return false;
        });

        try {
            $fn();
        } finally {
            restore_error_handler();
            unset($previous);
        }

        return $captured;
    }
}
