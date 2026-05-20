<?php

namespace Admnio\Sunset\Supervisor;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class SupervisorOptions
{
    public string $name;
    public string $connection;
    public string $queue;
    public string $workersName;
    public string $balance;
    public ?string $autoScalingStrategy;
    public int $backoff;
    public int $maxTime;
    public int $maxJobs;
    public int $maxProcesses;
    public int $minProcesses;
    public int $memory;
    public int $timeout;
    public int $sleep;
    public int $maxTries;
    public bool $force;
    public int $nice;
    public int $balanceCooldown;
    public int $balanceMaxShift;
    public int $parentId;
    public int $rest;
    public string $directory;

    public function __construct(
        string $name,
        string $connection,
        ?string $queue = null,
        string $workersName = 'default',
        string $balance = 'off',
        int $backoff = 0,
        int $maxTime = 0,
        int $maxJobs = 0,
        int $maxProcesses = 1,
        int $minProcesses = 1,
        int $memory = 128,
        int $timeout = 60,
        int $sleep = 3,
        int $maxTries = 0,
        bool $force = false,
        int $nice = 0,
        int $balanceCooldown = 3,
        int $balanceMaxShift = 1,
        int $parentId = 0,
        int $rest = 0,
        ?string $autoScalingStrategy = 'time',
    ) {
        $this->name = $name;
        $this->connection = $connection;
        $this->queue = $queue ?: (string) config('queue.connections.'.$connection.'.queue', 'default');
        $this->workersName = $workersName;
        $this->balance = $balance;
        $this->backoff = $backoff;
        $this->maxTime = $maxTime;
        $this->maxJobs = $maxJobs;
        $this->maxProcesses = $maxProcesses;
        $this->minProcesses = $minProcesses;
        $this->memory = $memory;
        $this->timeout = $timeout;
        $this->sleep = $sleep;
        $this->maxTries = $maxTries;
        $this->force = $force;
        $this->nice = $nice;
        $this->balanceCooldown = $balanceCooldown;
        $this->balanceMaxShift = $balanceMaxShift;
        $this->parentId = $parentId;
        $this->rest = $rest;
        $this->autoScalingStrategy = $autoScalingStrategy;
        $this->directory = base_path();
    }

    public function withQueue(string $queue): self
    {
        $clone = clone $this;
        $clone->queue = $queue;
        return $clone;
    }

    public function balancing(): bool
    {
        return in_array($this->balance, ['simple', 'auto'], true);
    }

    public function autoScaling(): bool
    {
        return $this->balance !== 'simple';
    }

    public function autoScaleByNumberOfJobs(): bool
    {
        return $this->autoScalingStrategy === 'size';
    }

    public function toSupervisorCommand(): string
    {
        return \Admnio\Sunset\Support\SupervisorCommandString::fromOptions($this);
    }

    public function toWorkerCommand(): string
    {
        return \Admnio\Sunset\Support\WorkerCommandString::fromOptions($this);
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    public function toArray(): array
    {
        return [
            'balance' => $this->balance,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'backoff' => $this->backoff,
            'force' => $this->force,
            'maxProcesses' => $this->maxProcesses,
            'minProcesses' => $this->minProcesses,
            'maxTries' => $this->maxTries,
            'maxTime' => $this->maxTime,
            'maxJobs' => $this->maxJobs,
            'memory' => $this->memory,
            'nice' => $this->nice,
            'name' => $this->name,
            'workersName' => $this->workersName,
            'sleep' => $this->sleep,
            'timeout' => $this->timeout,
            'balanceCooldown' => $this->balanceCooldown,
            'balanceMaxShift' => $this->balanceMaxShift,
            'parentId' => $this->parentId,
            'rest' => $this->rest,
            'autoScalingStrategy' => $this->autoScalingStrategy,
        ];
    }

    public static function fromArray(array $array): self
    {
        $instance = new self(
            name: $array['name'],
            connection: $array['connection'],
        );
        foreach ($array as $key => $value) {
            if (property_exists($instance, $key)) {
                $instance->{$key} = $value;
            }
        }
        return $instance;
    }
}
