<?php

namespace Admnio\Sunset\RateLimiting;

use Admnio\Sunset\RateLimiting\Targets\JobClassTarget;
use Admnio\Sunset\RateLimiting\Targets\QueueTarget;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class LimitRegistry
{
    /** @var array<string, Limit> */
    private array $byName = [];

    /** @var array<string, list<string>> */
    private array $byQueue = [];

    /** @var array<string, list<string>> */
    private array $byJobClass = [];

    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function upsert(Limit $limit): void
    {
        if (isset($this->byName[$limit->name])) {
            $this->removeFromIndexes($this->byName[$limit->name]);
        }

        $this->byName[$limit->name] = $limit;

        if ($limit->target instanceof QueueTarget) {
            $this->byQueue[$limit->target->queueName] ??= [];
            $this->byQueue[$limit->target->queueName][] = $limit->name;
        } else {
            /** @var JobClassTarget $t */
            $t = $limit->target;
            $this->byJobClass[$t->jobClass] ??= [];
            $this->byJobClass[$t->jobClass][] = $limit->name;
        }
    }

    private function removeFromIndexes(Limit $limit): void
    {
        if ($limit->target instanceof QueueTarget) {
            $q = $limit->target->queueName;
            $this->byQueue[$q] = array_values(
                array_filter($this->byQueue[$q] ?? [], fn ($n) => $n !== $limit->name)
            );
        } else {
            /** @var JobClassTarget $t */
            $t = $limit->target;
            $this->byJobClass[$t->jobClass] = array_values(
                array_filter($this->byJobClass[$t->jobClass] ?? [], fn ($n) => $n !== $limit->name)
            );
        }
    }

    /**
     * Resolve all matching limits for a popped job, applying each limit's `when` condition.
     *
     * @param  mixed  $job
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $tags
     * @return list<Limit>
     */
    public function resolve($job, array $payload, string $queueName, array $tags): array
    {
        $candidateNames = $this->byQueue[$queueName] ?? [];

        $jobClass = $payload['data']['commandName'] ?? null;
        if ($jobClass !== null && isset($this->byJobClass[$jobClass])) {
            $candidateNames = array_merge($candidateNames, $this->byJobClass[$jobClass]);
        }

        $matches = [];
        foreach ($candidateNames as $name) {
            $limit = $this->byName[$name];
            if ($limit->condition === null) {
                $matches[] = $limit;

                continue;
            }
            try {
                if ((bool) ($limit->condition)($job, $payload, $queueName, $tags)) {
                    $matches[] = $limit;
                }
            } catch (Throwable $e) {
                $this->logger->warning(
                    "Sunset rate-limit condition for '{$limit->name}' threw: ".$e->getMessage()
                );
            }
        }

        return $matches;
    }

    /** @return list<Limit> */
    public function all(): array
    {
        return array_values($this->byName);
    }

    public function isEmpty(): bool
    {
        return $this->byName === [];
    }
}
