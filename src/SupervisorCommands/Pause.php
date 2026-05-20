<?php

namespace Admnio\Sunset\SupervisorCommands;

use Admnio\Sunset\Supervisor\Supervisor;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class Pause
{
    /**
     * Process the command.
     *
     * @param  \Admnio\Sunset\Supervisor\Supervisor  $supervisor
     * @param  array  $options
     * @return void
     */
    public function process(Supervisor $supervisor, array $options): void
    {
        $supervisor->pause();
    }
}
