<?php

declare(strict_types=1);

namespace PureCache\Ignite;

use PureCache\Internal\ClientCoreState;
use PureCache\Internal\ClientOptions;
use PureCache\Internal\ServerSelector;

/**
 * Mutable connection state shared by {@see IgniteClient} instances that reuse a
 * {@code persistent_id}. One {@link NativeIgniteClient} per server index, plus
 * the resolved Ignite cacheId so we only hash the cache name once per session.
 */
final class IgniteClientState extends ClientCoreState
{
    /** @var array<int, NativeIgniteClient> */
    public array $clientByServerIndex = [];

    /** @var array<int, int> server index → resolved Ignite cache id */
    public array $cacheIdByServerIndex = [];

    private function __construct()
    {
    }

    public static function createFresh(?string $persistentId = null): self
    {
        $state = new self();
        $state->persistentId = $persistentId;
        $state->selector = new ServerSelector();
        $state->options = ClientOptions::defaults();

        return $state;
    }
}
