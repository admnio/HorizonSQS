<?php

namespace Admnio\Sunset\MasterSupervisorCommands;

use Admnio\Sunset\Supervisor\MasterSupervisor;
use Admnio\Sunset\Supervisor\SupervisorOptions;

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
