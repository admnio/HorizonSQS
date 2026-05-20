<?php

namespace Admnio\Sunset\MasterSupervisorCommands;

use Admnio\Sunset\Supervisor\MasterSupervisor;
use Admnio\Sunset\Supervisor\SupervisorOptions;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class AddSupervisor
{
    /**
     * Process the command.
     *
     * @param  \Admnio\Sunset\Supervisor\MasterSupervisor  $master
     * @param  array  $options
     * @return void
     */
    public function process(MasterSupervisor $master, array $options): void
    {
        $master->addSupervisor(SupervisorOptions::fromArray($options));
    }
}
