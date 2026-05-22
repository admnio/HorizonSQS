<?php

namespace Admnio\Sunset\SupervisorCommands;

use Admnio\Sunset\Supervisor\Supervisor;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 *
 * Adjusts the supervisor's target process count. The supervisor's autoscaler
 * (or manual balance() call) will bring actual worker count to match on the
 * next monitoring loop tick.
 *
 * Accepts either `processes` (the v2.3 dashboard-driven key) or `scale` (the
 * pre-v2.3 internal key) so older queued commands keep dispatching cleanly
 * after upgrade. Invalid / non-positive values are ignored rather than thrown
 * so a single bad payload can't crash the supervisor's main loop.
 */
class Scale
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
        $target = (int) ($options['processes'] ?? $options['scale'] ?? 0);

        if ($target < 1) {
            return; // ignore invalid scale requests rather than crashing the supervisor loop
        }

        $supervisor->scale($target);
    }
}
