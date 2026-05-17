<?php

declare(strict_types=1);

namespace PureCache\Internal;

use PureCache\MemcachedConstants;

/**
 * PECL-shaped {@code getOption()} / {@code setOption()} surface.
 *
 * @psalm-import-type ClientOptionsMap from PsalmTypes
 *
 * @psalm-suppress MixedAssignment
 */
final readonly class ClientOptionAccessor
{
    public function __construct(
        private ClientCoordinatorEnv $env,
        private OptionEnvironment $environment,
    ) {
    }

    public function get(int $option): mixed
    {
        if (
            MemcachedConstants::OPT_LIBKETAMA_HASH === $option
            && !LibketamaHashOptionParity::setterUpdatesStoredKetamaGetter()
        ) {
            $option = MemcachedConstants::OPT_HASH;
        }

        $value = $this->env->core->options[$option] ?? null;
        if (\is_bool($value) && self::returnsIntegerBoolean($option)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    public function set(int $option, mixed $value): bool
    {
        $result = ClientOptionApplier::apply($this->env->core, $option, $value, $this->environment);
        $this->env->setResult($result->code, $result->message);

        return $result->ok;
    }

    /**
     * @param array<mixed> $options
     */
    public function setMany(array $options): bool
    {
        $ok = true;
        foreach ($options as $k => $v) {
            if (!\is_int($k)) {
                $ok = false;
                continue;
            }

            if (!$this->set($k, $v)) {
                $ok = false;
            }
        }

        return $ok;
    }

    public static function returnsIntegerBoolean(int $option): bool
    {
        return \in_array($option, [
            MemcachedConstants::OPT_BUFFER_WRITES,
            MemcachedConstants::OPT_HASH_WITH_PREFIX_KEY,
            MemcachedConstants::OPT_LIBKETAMA_COMPATIBLE,
            MemcachedConstants::OPT_NO_BLOCK,
            MemcachedConstants::OPT_NOREPLY,
            MemcachedConstants::OPT_TCP_KEEPALIVE,
            MemcachedConstants::OPT_TCP_NODELAY,
            MemcachedConstants::OPT_VERIFY_KEY,
            MemcachedConstants::OPT_SUPPORT_CAS,
        ], true);
    }
}
