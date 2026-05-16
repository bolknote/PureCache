<?php

declare(strict_types=1);

namespace PureCache\Internal;

/**
 * Whole-pool fan-out for {@code getStats()}, {@code getVersion()}, {@code flush()},
 * and {@code getAllKeys()}.
 */
final readonly class ClientPoolCoordinator
{
    public function __construct(
        private ClientCoordinatorEnv $env,
        private ClientHealthRecorder $health,
    ) {
    }

    /**
     * @template TValue
     *
     * @param \Closure(int $serverIndex, array{host:string,port:int,weight:int,user?:string,password?:string,database?:int,tls?:bool,tls_ca_file?:string}): (TValue|null) $task
     * @param TValue                                                                                                                                                      $failureValue
     *
     * @return array{values: array<string, TValue>, allOk: bool, anyOk: bool}
     */
    public function collectFromServers(\Closure $task, mixed $failureValue): array
    {
        $values = [];
        $allOk = true;
        $anyOk = false;
        foreach ($this->env->core->selector->getServers() as $i => $server) {
            $label = $server['host'].':'.$server['port'];
            try {
                $result = $task($i, $server);
                if (null === $result) {
                    $values[$label] = $failureValue;
                    $allOk = false;
                    continue;
                }

                $values[$label] = $result;
                $anyOk = true;
            } catch (\Throwable $throwable) {
                $this->health->recordServerFailure($i, $throwable);
                $values[$label] = $failureValue;
                $allOk = false;
            }
        }

        return ['values' => $values, 'allOk' => $allOk, 'anyOk' => $anyOk];
    }
}
