<?php

namespace Admnio\Sunset\Console;

use Illuminate\Console\Command;
use Admnio\Sunset\Transports\Sqs\Delay\DelayedJobReenqueuer;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class SweepDelayedCommand extends Command
{
    protected $signature = 'sunset:sweep-delayed';

    protected $description = 'Push long-delayed jobs whose ETA falls within the next sweep interval back to SQS.';

    public function handle(DelayedJobReenqueuer $reenqueuer): int
    {
        $reenqueuer->sweep();
        return self::SUCCESS;
    }
}
