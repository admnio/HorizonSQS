<?php

namespace Admnio\Sunset\Support;

use Admnio\Sunset\Supervisor\SupervisorOptions;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class WorkerCommandString
{
    public static function fromOptions(SupervisorOptions $options): string
    {
        $command = "exec \"%s\" artisan sunset:worker %s --name=%s --supervisor=%s --backoff=%s --max-time=%s --max-jobs=%s --memory=%s --queue=%s --sleep=%s --timeout=%s --tries=%s";

        if ($options->rest) {
            $command .= ' --rest='.$options->rest;
        }
        if ($options->force) {
            $command .= ' --force';
        }

        return sprintf(
            $command,
            self::phpBinary(),
            $options->connection,
            $options->workersName,
            $options->name,
            $options->backoff,
            $options->maxTime,
            $options->maxJobs,
            $options->memory,
            escapeshellarg($options->queue),
            $options->sleep,
            $options->timeout,
            $options->maxTries,
        );
    }

    protected static function phpBinary(): string
    {
        return (new \Symfony\Component\Process\PhpExecutableFinder)->find(false);
    }
}
