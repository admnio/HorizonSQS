<?php

namespace Admnio\Sunset\Listeners;

use Admnio\Sunset\Contracts\FailedJobRepository;
use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Events\JobFailed;
use RuntimeException;
use Throwable;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class MarkJobAsFailed
{
    public function __construct(
        private FailedJobRepository $failed,
        private JobRepository $jobs,
    ) {}

    public function handle(JobFailed $event): void
    {
        $exception = $this->reconstructException($event->payload->decoded['exception_data'] ?? null);

        $this->failed->failed($exception, $event->connectionName, $event->queue, $event->payload);

        // Mirror as "completed (failed)" in the job timeline so dashboards that
        // page through completed_jobs see failures in their normal flow.
        $this->jobs->completed($event->payload, silenced: false);
    }

    private function reconstructException(?string $data): Throwable
    {
        if (! $data) {
            return new RuntimeException('Unknown failure');
        }
        $decoded = json_decode($data, true) ?? [];
        $class = $decoded['class'] ?? RuntimeException::class;
        $message = $decoded['message'] ?? 'Unknown failure';
        return class_exists($class) && is_subclass_of($class, Throwable::class)
            ? new $class($message)
            : new RuntimeException("[{$class}] {$message}");
    }
}
