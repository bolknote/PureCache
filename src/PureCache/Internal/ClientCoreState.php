<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Mutable state shared by client instances that participate in PECL-style
 * in-process persistent pooling (same {@code persistent_id}). Subclasses add
 * backend-specific resources (connection pools, transport handles, etc.).
 *
 * @psalm-import-type ClientOptionsMap from PsalmTypes
 */
abstract class ClientCoreState
{
    public ServerSelector $selector;

    public ServerFailureTracker $failureTracker;

    /** @var ClientOptionsMap */
    public array $options = [];

    public int $compressionThreshold = 2000;

    public float $compressionFactor = 1.30;

    public int $resultCode = MemcachedConstants::RES_SUCCESS;

    public string $resultMessage = '';

    public int $lastErrorErrno = 0;

    /** @var array{host:string,port:int,weight:int,type:string}|null */
    public ?array $lastDisconnectedServer = null;

    /** @var list<array{keys:list<string>, serverKey:?string, withCas:bool}> */
    public array $delayedQueue = [];

    public int $delayedCursor = 0;

    /** @var list<array<string, mixed>>|null */
    public ?array $delayedResults = null;

    public ?string $persistentId = null;

    /**
     * Active encoding/encryption context attached by
     * {@see \PureCache\AbstractCacheClient::setEncodingKey()}; {@code null}
     * means encryption is off and {@see ValueCodec} runs the plain
     * serialize→compress pipeline.
     */
    public ?EncodingContext $encoding = null;

    public ?ClientObserver $observer = null;

    /**
     * Set when {@code setOption(OPT_LIBKETAMA_HASH)} runs; cleared on
     * {@code OPT_HASH} / {@code OPT_LIBKETAMA_COMPATIBLE} changes. Until then
     * {@code getOption(OPT_LIBKETAMA_HASH)} tracks {@code OPT_HASH} like PECL.
     */
    public bool $libketamaHashDialTouched = false;

    protected function __construct(?string $persistentId = null)
    {
        $this->initDefaults($persistentId);
    }

    protected function initDefaults(?string $persistentId): void
    {
        $this->persistentId = $persistentId;
        $this->failureTracker = new ServerFailureTracker();
        $this->selector = new ServerSelector();
        $this->selector->setFailureTracker($this->failureTracker);

        $this->options = ClientOptions::defaults();
        $this->libketamaHashDialTouched = false;
        $this->resultCode = MemcachedConstants::RES_SUCCESS;
        $this->resultMessage = '';
    }

    /**
     * Layers {@code memcached.*} php.ini defaults on top of {@see ClientOptions::defaults()},
     * exactly as PECL's constructor does in {@code php_memcached.c} around lines 1351-1392
     * (instance fields are seeded from {@code MEMC_G(...)}, then
     * {@code default_behavior.*} feeds through to {@code memcached_behavior_set()}).
     *
     * Only call this from the Memcached backend — Redis and Ignite have no
     * documented INI surface and must not be affected by ext-memcached settings.
     *
     * @param array{
     *   serializer:int,
     *   compression_type:int,
     *   compression_level:int,
     *   compression_threshold:int,
     *   compression_factor:float,
     *   store_retry_count:int,
     *   item_size_limit:int,
     *   default_consistent_hash:bool,
     *   default_binary_protocol:bool,
     *   default_connect_timeout:int,
     * } $snapshot
     */
    public function applyIniDefaults(array $snapshot): void
    {
        $this->options[MemcachedConstants::OPT_SERIALIZER] = $snapshot['serializer'];

        $this->options[MemcachedConstants::OPT_COMPRESSION_TYPE] = $snapshot['compression_type'];
        $this->options[MemcachedConstants::OPT_COMPRESSION_LEVEL] = $snapshot['compression_level'];
        $this->options[MemcachedConstants::OPT_STORE_RETRY_COUNT] = $snapshot['store_retry_count'];
        $this->options[MemcachedConstants::OPT_ITEM_SIZE_LIMIT] = $snapshot['item_size_limit'];

        $this->compressionThreshold = $snapshot['compression_threshold'];
        $this->compressionFactor = $snapshot['compression_factor'];

        if ($snapshot['default_consistent_hash']) {
            $this->options[MemcachedConstants::OPT_DISTRIBUTION] = MemcachedConstants::DISTRIBUTION_CONSISTENT;
            $this->selector->setDistribution(MemcachedConstants::DISTRIBUTION_CONSISTENT);
        }

        if ($snapshot['default_binary_protocol']) {
            $this->options[MemcachedConstants::OPT_BINARY_PROTOCOL] = true;
            trigger_error(
                'memcached.default_binary_protocol=On is ignored: PureCache speaks the meta protocol exclusively',
                \E_USER_WARNING,
            );
        }

        if (0 !== $snapshot['default_connect_timeout']) {
            $this->options[MemcachedConstants::OPT_CONNECT_TIMEOUT] = $snapshot['default_connect_timeout'];
        }
    }

    public function optionInt(int $option, int $default): int
    {
        return isset($this->options[$option]) && \is_int($this->options[$option])
            ? $this->options[$option]
            : $default;
    }

    public function optionBool(int $option, bool $default): bool
    {
        return isset($this->options[$option]) && \is_bool($this->options[$option])
            ? $this->options[$option]
            : $default;
    }
}
