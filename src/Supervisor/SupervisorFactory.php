<?php

namespace Admnio\Sunset\Supervisor;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
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
