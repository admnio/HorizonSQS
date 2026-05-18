<?php

namespace Admnio\Sunset\Tests\Unit\Console;

use Admnio\Sunset\Console\SweepDelayedCommand;
use Admnio\Sunset\Queue\Delay\DelayedJobReenqueuer;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class SweepDelayedCommandTest extends TestCase
{
    public function test_invokes_reenqueuer(): void
    {
        $reenqueuer = Mockery::mock(DelayedJobReenqueuer::class);
        $reenqueuer->shouldReceive('sweep')->once();
        $this->app->instance(DelayedJobReenqueuer::class, $reenqueuer);

        // Manually register the command since Batch 6 will do it via the provider
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)
            ->registerCommand(new SweepDelayedCommand());

        $this->artisan('horizon-sqs:sweep-delayed')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
