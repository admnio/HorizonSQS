<?php

namespace Admnio\Sunset\Support;

use Admnio\Sunset\Supervisor\SupervisorOptions;

class SupervisorCommandString
{
    public static function fromOptions(SupervisorOptions $options): string
    {
        $command = "exec \"%s\" artisan sunset:supervise '%s' %s --workers-name=%s --balance=%s --max-processes=%s --min-processes=%s --balance-cooldown=%s --balance-max-shift=%s --parent-id=%s";

        if (! is_null($options->autoScalingStrategy)) {
            $command .= " --auto-scaling-strategy=".$options->autoScalingStrategy;
        }

        $command .= self::optionFlags($options);

        return sprintf(
            $command,
            self::phpBinary(),
            $options->name,
            $options->connection,
            $options->workersName,
            $options->balance,
            $options->maxProcesses,
            $options->minProcesses,
            $options->balanceCooldown,
            $options->balanceMaxShift,
            $options->parentId,
        );
    }

    protected static function optionFlags(SupervisorOptions $options): string
    {
        $flags = '';
        if ($options->queue) {
            $flags .= ' --queue='.escapeshellarg($options->queue);
        }
        if ($options->backoff > 0) {
            $flags .= ' --backoff='.$options->backoff;
        }
        if ($options->maxTime > 0) {
            $flags .= ' --max-time='.$options->maxTime;
        }
        if ($options->maxJobs > 0) {
            $flags .= ' --max-jobs='.$options->maxJobs;
        }
        if ($options->memory) {
            $flags .= ' --memory='.$options->memory;
        }
        if ($options->timeout) {
            $flags .= ' --timeout='.$options->timeout;
        }
        if ($options->sleep) {
            $flags .= ' --sleep='.$options->sleep;
        }
        if ($options->maxTries) {
            $flags .= ' --tries='.$options->maxTries;
        }
        if ($options->rest) {
            $flags .= ' --rest='.$options->rest;
        }
        if ($options->force) {
            $flags .= ' --force';
        }
        if ($options->nice) {
            $flags .= ' --nice='.$options->nice;
        }
        return $flags;
    }

    protected static function phpBinary(): string
    {
        return (new \Symfony\Component\Process\PhpExecutableFinder)->find(false);
    }
}
