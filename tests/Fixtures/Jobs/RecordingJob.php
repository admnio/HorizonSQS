<?php

namespace Admnio\Sunset\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecordingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $marker)
    {
    }

    public function handle(): void
    {
        file_put_contents(sys_get_temp_dir() . '/horizon-sqs-marker', $this->marker);
    }
}
