<?php

namespace Admnio\Sunset\SupervisorCommands;

use Admnio\Sunset\Supervisor\Supervisor;

class Balance
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
        $supervisor->balance($options);
    }
}
