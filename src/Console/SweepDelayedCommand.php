<?php

namespace Admnio\Sunset\Console;

use Illuminate\Console\Command;
use Admnio\Sunset\Queue\Delay\DelayedJobReenqueuer;

class SweepDelayedCommand extends Command
{
    protected $signature = 'horizon-sqs:sweep-delayed';

    protected $description = 'Push long-delayed jobs whose ETA falls within the next sweep interval back to SQS.';

    public function handle(DelayedJobReenqueuer $reenqueuer): int
    {
        $reenqueuer->sweep();
        return self::SUCCESS;
    }
}
