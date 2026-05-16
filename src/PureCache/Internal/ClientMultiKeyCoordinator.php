<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * Shared {@code getMulti*} / {@code deleteMulti*} orchestration for all backends.
 *
 * @psalm-suppress MixedAssignment
 */
final readonly class ClientMultiKeyCoordinator
{
    /**
     * @param \Closure(): void                                                   $flushNetworkWrites
     * @param \Closure(array<mixed>): list<string>                               $keyStrings
     * @param \Closure(mixed): string                                            $keyToString
     * @param \Closure(string): bool                                             $checkKeyInternal
     * @param \Closure(string): string                                           $prefixedKey
     * @param \Closure(): bool                                                   $ensureServersAvailable
     * @param \Closure(list<string>, ?string, int): (array<string, mixed>|false) $doGetMulti
     * @param \Closure(string, ?string, int): bool                               $doDelete
     * @param \Closure(int): bool                                                $acceptDeleteTime
     */
    public function __construct(
        private ClientCoordinatorEnv $env,
        private \Closure $flushNetworkWrites,
        private \Closure $keyStrings,
        private \Closure $keyToString,
        private \Closure $checkKeyInternal,
        private \Closure $prefixedKey,
        private \Closure $ensureServersAvailable,
        private \Closure $doGetMulti,
        private \Closure $doDelete,
        private \Closure $acceptDeleteTime,
    ) {
    }

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, mixed>|false
     */
    public function getMulti(array $keys, ?string $serverKey, int $getFlags): array|false
    {
        if (null !== $serverKey && !($this->checkKeyInternal)($serverKey)) {
            $this->env->setResult(MemcachedConstants::RES_BAD_KEY_PROVIDED);

            return false;
        }

        ($this->flushNetworkWrites)();

        if ([] === $keys) {
            $this->env->setResult(MemcachedConstants::RES_SUCCESS);

            return [];
        }

        $keyStrings = ($this->keyStrings)($keys);
        foreach ($keyStrings as $ks) {
            if (!($this->checkKeyInternal)(($this->prefixedKey)($ks))) {
                $this->env->setResult(MemcachedConstants::RES_BAD_KEY_PROVIDED);

                return false;
            }
        }

        if (!($this->ensureServersAvailable)()) {
            return false;
        }

        $found = ($this->doGetMulti)($keyStrings, $serverKey, $getFlags);
        if (false === $found) {
            return false;
        }

        if (MemcachedConstants::RES_SOME_ERRORS !== $this->env->getResultCode()) {
            $this->env->setResult(MemcachedConstants::RES_SUCCESS);
        }

        if (($getFlags & MemcachedConstants::GET_PRESERVE_ORDER) !== 0) {
            $ordered = [];
            foreach ($keyStrings as $ks) {
                $ordered[$ks] = $found[$ks] ?? null;
            }

            return $ordered;
        }

        return $found;
    }

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, bool|int>
     */
    public function deleteMulti(array $keys, ?string $serverKey, int $time): array
    {
        $keyStrings = ($this->keyStrings)($keys);
        if (null !== $serverKey && !($this->checkKeyInternal)($serverKey)) {
            $this->env->setResult(MemcachedConstants::RES_BAD_KEY_PROVIDED);

            return array_fill_keys($keyStrings, MemcachedConstants::RES_BAD_KEY_PROVIDED);
        }

        foreach ($keys as $k) {
            if (!($this->checkKeyInternal)(($this->prefixedKey)(($this->keyToString)($k)))) {
                $this->env->setResult(MemcachedConstants::RES_BAD_KEY_PROVIDED);

                return array_fill_keys($keyStrings, MemcachedConstants::RES_BAD_KEY_PROVIDED);
            }
        }

        if (!($this->acceptDeleteTime)($time)) {
            return array_fill_keys($keyStrings, $this->env->getResultCode());
        }

        $out = [];
        foreach ($keys as $k) {
            $ks = ($this->keyToString)($k);
            $ok = ($this->doDelete)($ks, $serverKey, 0);
            $out[$ks] = $ok ? true : $this->env->getResultCode();
        }

        return $out;
    }
}
