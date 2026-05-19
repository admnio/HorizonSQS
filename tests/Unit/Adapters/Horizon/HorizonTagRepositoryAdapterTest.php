<?php

namespace Admnio\Sunset\Tests\Unit\Adapters\Horizon;

use Admnio\Sunset\Adapters\Horizon\HorizonTagRepositoryAdapter;
use Admnio\Sunset\Contracts\TagRepository as SunsetTagRepo;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Support\Collection;
use Mockery;

class HorizonTagRepositoryAdapterTest extends TestCase
{
    public function test_add_delegates_to_add_permanent(): void
    {
        $tags = Mockery::mock(SunsetTagRepo::class);
        $tags->shouldReceive('addPermanent')->once()->with('job-1', ['a', 'b']);

        $adapter = new HorizonTagRepositoryAdapter($tags);
        $adapter->add('job-1', ['a', 'b']);
    }

    public function test_add_temporary_converts_minutes_to_expires_at(): void
    {
        $tags = Mockery::mock(SunsetTagRepo::class);
        $tags->shouldReceive('addTemporary')->once()
            ->withArgs(function ($expiresAt, $id, $tagArr) {
                return $expiresAt > time()
                    && $expiresAt <= time() + 60 + 1
                    && $id === 'job-1'
                    && $tagArr === ['payments'];
            });

        $adapter = new HorizonTagRepositoryAdapter($tags);
        $adapter->addTemporary(1, 'job-1', ['payments']); // 1 minute
    }

    public function test_jobs_returns_array(): void
    {
        $tags = Mockery::mock(SunsetTagRepo::class);
        $tags->shouldReceive('jobs')->with('vip', null)->andReturn(new Collection(['a', 'b']));

        $adapter = new HorizonTagRepositoryAdapter($tags);
        $this->assertSame(['a', 'b'], $adapter->jobs('vip'));
    }

    public function test_paginate_converts_starting_at_to_after_index(): void
    {
        $tags = Mockery::mock(SunsetTagRepo::class);
        $tags->shouldReceive('jobs')->with('vip', '4')->andReturn(new Collection(['five', 'six']));
        $tags->shouldReceive('count')->with('vip')->andReturn(10);

        $adapter = new HorizonTagRepositoryAdapter($tags);
        $result = $adapter->paginate('vip', 5, 25);

        $this->assertSame(['five', 'six'], $result['jobs']);
        $this->assertSame(10, $result['total']);
    }

    public function test_monitoring_aliases_monitored(): void
    {
        $tags = Mockery::mock(SunsetTagRepo::class);
        $tags->shouldReceive('monitored')->andReturn(['vip', 'critical']);

        $adapter = new HorizonTagRepositoryAdapter($tags);
        $this->assertSame(['vip', 'critical'], $adapter->monitoring());
    }

    public function test_monitored_with_tags_filters_to_monitored_intersection(): void
    {
        $tags = Mockery::mock(SunsetTagRepo::class);
        $tags->shouldReceive('monitored')->andReturn(['vip', 'critical']);

        $adapter = new HorizonTagRepositoryAdapter($tags);
        $result = $adapter->monitored(['vip', 'spam']);
        $this->assertSame(['vip'], array_values($result));
    }

    public function test_monitor_stop_count_forget_delegate(): void
    {
        $tags = Mockery::mock(SunsetTagRepo::class);
        $tags->shouldReceive('monitor')->with('vip')->once();
        $tags->shouldReceive('stopMonitoring')->with('vip')->once();
        $tags->shouldReceive('count')->with('vip')->andReturn(7);
        $tags->shouldReceive('forget')->with('vip')->once();

        $adapter = new HorizonTagRepositoryAdapter($tags);
        $adapter->monitor('vip');
        $adapter->stopMonitoring('vip');
        $this->assertSame(7, $adapter->count('vip'));
        $adapter->forget('vip');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
