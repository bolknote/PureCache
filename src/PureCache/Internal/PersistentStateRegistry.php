<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * In-process registry of {@see ClientCoreState} instances keyed by
 * {@code persistent_id}, used to mirror PECL {@code \Memcached}'s persistent-pool
 * semantics. Each backend class gets its own bucket via {@code static::class},
 * so the same {@code persistent_id} chosen for Memcached, Redis and Ignite
 * never collide.
 *
 * Subclasses of {@see \PureCache\AbstractCacheClient} pull in this trait via
 *
 * {@code /** @use PersistentStateRegistry<ConcreteState> *\/} and automatically
 * satisfy the {@code lookupPersistentState()} / {@code registerPersistentState()}
 * extension points.
 *
 * @template TState of ClientCoreState
 */
trait PersistentStateRegistry
{
    /** @var array<class-string, array<string, ClientCoreState>> */
    private static array $persistentPoolByBackend = [];

    /**
     * @return TState|null
     */
    protected function lookupPersistentState(string $persistentId): ?ClientCoreState
    {
        /** @var TState|null $state */
        $state = self::$persistentPoolByBackend[static::class][$persistentId] ?? null;

        return $state;
    }

    /**
     * @param TState $state
     */
    protected function registerPersistentState(string $persistentId, ClientCoreState $state): void
    {
        self::$persistentPoolByBackend[static::class][$persistentId] = $state;
    }
}
