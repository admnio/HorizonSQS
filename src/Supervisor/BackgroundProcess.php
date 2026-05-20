<?php

namespace Admnio\Sunset\Supervisor;

use Symfony\Component\Process\Process;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class BackgroundProcess extends Process
{
    /**
     * Destruct the object.
     *
     * @return void
     */
    public function __destruct()
    {
        //
    }
}
