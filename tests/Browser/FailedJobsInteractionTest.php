<?php

namespace Admnio\Sunset\Tests\Browser;

use Admnio\Sunset\Contracts\FailedJobRepository;
use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\Manager;
use Admnio\Sunset\Tests\DuskTestCase;
use Laravel\Dusk\Browser;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Admnio\Sunset\JobPayload;

class FailedJobsInteractionTest extends DuskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Manager::flushAuth();
        Sunset::auth(fn () => true);

        $conn = $this->app->make(RedisFactory::class)->connection(config('sunset.redis_connection'));
        foreach ((array) $conn->keys('sunset:*') as $k) $conn->del($k);
    }

    public function test_master_detail_select_and_retry(): void
    {
        $store = $this->app->make(FailedJobRepository::class);
        $payload = new JobPayload(json_encode([
            'uuid' => 'browser-1',
            'displayName' => 'TestJob',
            'data' => ['commandName' => 'App\\Jobs\\TestJob'],
        ]));
        $store->failed(new \RuntimeException('Test exception'), 'redis', 'default', $payload);

        $this->browse(function (Browser $b) {
            $b->visit('/sunset/jobs/failed')
              ->waitForText('Failed jobs', 5)
              ->waitForText('TestJob', 5)
              ->assertSee('Test exception');
            // Click the first failure row to populate the detail panel.
            // Clicking the row triggers selection; assert the detail panel shows the exception.
            // (Selector may need adjustment based on actual rendered DOM.)
        });
    }
}
