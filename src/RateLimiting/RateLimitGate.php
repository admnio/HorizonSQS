<?php

namespace Admnio\Sunset\RateLimiting;

use Admnio\Sunset\Contracts\Limiter;
use Admnio\Sunset\Events\JobRateLimited;
use Admnio\Sunset\Exceptions\RateLimitExceededException;
use Admnio\Sunset\JobPayload;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

class RateLimitGate
{
    private const RESERVATIONS_KEY_PREFIX = 'sunset:rl:reservations:';
    private const REJECT_COUNTER_PREFIX = 'sunset:rl:rejects:';

    private LoggerInterface $logger;

    public function __construct(
        private LimitRegistry $registry,
        private Limiter $limiter,
        private RedisFactory $redis,
        private string $redisConnection,
        private bool $failClosed,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $tags
     */
    public function admit(JobContract $job, array $payload, string $queueName, array $tags): Decision
    {
        if ($this->registry->isEmpty()) {
            return Decision::admit();
        }

        $matched = $this->registry->resolve($job, $payload, $queueName, $tags);
        if ($matched === []) {
            return Decision::admit();
        }

        $perLimitDecisions = [];
        $reservationsByLimit = [];

        foreach ($matched as $limit) {
            try {
                $bucketKey = $this->bucketKeyFor($limit, $job, $payload, $queueName, $tags);
                $d = $this->limiter->check($limit, $bucketKey);
            } catch (Throwable $e) {
                $this->logger->warning(
                    "Sunset RateLimitGate: limiter threw for '{$limit->name}': " . $e->getMessage()
                );
                $d = $this->failClosed
                    ? Decision::reject(30)
                    : Decision::admit();
            }

            $perLimitDecisions[] = $d;
            if ($d->admitted) {
                $reservationsByLimit[$limit->name] = $d->reservations;
            } else {
                // Roll back any prior admits in this iteration immediately.
                foreach ($reservationsByLimit as $prior) {
                    $this->limiter->rollback($prior);
                }
                return $this->applyReject($limit, $d, $job, $payload, $queueName);
            }
        }

        $final = Decision::merge($perLimitDecisions);
        if ($final->admitted) {
            $this->storeReservations($job, $final->reservations, $matched);
        }
        return $final;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyReject(
        Limit $limit,
        Decision $decision,
        JobContract $job,
        array $payload,
        string $queueName
    ): Decision {
        $connection = $payload['connection'] ?? 'default';
        $jobPayload = new JobPayload(json_encode($payload));

        // Increment the rejects counter for the dashboard observability.
        $conn = $this->redis->connection($this->redisConnection);
        $counterKey = self::REJECT_COUNTER_PREFIX . "{$connection}:{$queueName}:{$limit->name}";
        $conn->incr($counterKey);
        $conn->expire(
            $counterKey,
            $limit->throttle !== null ? $limit->throttle->windowSeconds : 60
        );

        event(new JobRateLimited(
            connection: $connection,
            queueName: $queueName,
            limitName: $limit->name,
            retryAfterSeconds: $decision->retryAfterSeconds,
            strategy: $limit->overLimit,
            payload: $jobPayload,
        ));

        switch ($limit->overLimit) {
            case 'release-computed':
                $job->release($decision->retryAfterSeconds);
                break;
            case 'release-fixed':
                $job->release($limit->fixedBackoffSeconds);
                break;
            case 'drop':
                if ($limit->dropAsFailure) {
                    $job->fail(new RateLimitExceededException($limit->name, $decision->retryAfterSeconds));
                } else {
                    $job->delete();
                    $this->logger->info(
                        "Sunset rate-limit '{$limit->name}' dropped job {$job->getJobId()} silently."
                    );
                }
                break;
        }

        return $decision;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $tags
     */
    private function bucketKeyFor(Limit $limit, JobContract $job, array $payload, string $queueName, array $tags): string
    {
        if ($limit->keyResolver === null) {
            return 'global';
        }
        try {
            $key = (string) ($limit->keyResolver)($job, $payload, $queueName, $tags);
            return $key !== '' ? $key : 'global';
        } catch (Throwable $e) {
            $this->logger->warning(
                "Sunset rate-limit by-key closure for '{$limit->name}' threw: " . $e->getMessage()
            );
            return 'global';
        }
    }

    /**
     * @param array<int, mixed> $reservations
     * @param list<Limit> $limits
     */
    private function storeReservations(JobContract $job, array $reservations, array $limits): void
    {
        $jobId = $job->getJobId();
        if ($jobId === null) {
            return;
        }

        $maxTtl = 60;
        foreach ($limits as $limit) {
            if ($limit->concurrency !== null) {
                $maxTtl = max($maxTtl, $limit->concurrency->slotTtlSeconds);
            }
        }

        $conn = $this->redis->connection($this->redisConnection);
        $conn->set(
            self::RESERVATIONS_KEY_PREFIX . $jobId,
            json_encode($reservations),
            'EX',
            $maxTtl + 5
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function readReservations(string $jobId): array
    {
        $conn = $this->redis->connection($this->redisConnection);
        $raw = $conn->get(self::RESERVATIONS_KEY_PREFIX . $jobId);
        if (! $raw) {
            return [];
        }
        return json_decode($raw, true) ?: [];
    }

    public function clearReservations(string $jobId): void
    {
        $this->redis->connection($this->redisConnection)
            ->del(self::RESERVATIONS_KEY_PREFIX . $jobId);
    }
}
