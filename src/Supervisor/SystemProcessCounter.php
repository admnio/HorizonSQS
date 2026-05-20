<?php

namespace Admnio\Sunset\Supervisor;

use Symfony\Component\Process\Process;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class SystemProcessCounter
{
    /**
     * The base command to search for.
     *
     * @var string
     */
    public static $command = 'sunset:worker';

    /**
     * Get the number of Sunset workers for a given supervisor.
     *
     * @param  string  $name
     * @return int
     */
    public function get($name)
    {
        $process = Process::fromShellCommandline(
            'exec ps aux | grep '.static::$command,
            null,
            ['COLUMNS' => '2000']
        );

        $process->run();

        return substr_count($process->getOutput(), 'supervisor='.$name);
    }
}
