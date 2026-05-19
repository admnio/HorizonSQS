<?php

namespace Admnio\Sunset\Supervisor;

class SupervisorFactory
{
    /**
     * Create a new supervisor instance.
     *
     * @param  \Admnio\Sunset\Supervisor\SupervisorOptions  $options
     * @return \Admnio\Sunset\Supervisor\Supervisor
     */
    public function make(SupervisorOptions $options)
    {
        return new Supervisor($options);
    }
}
