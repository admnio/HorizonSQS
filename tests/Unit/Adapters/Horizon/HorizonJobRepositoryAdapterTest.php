<?php

namespace Admnio\Sunset\Tests\Unit\Adapters\Horizon;

use Admnio\Sunset\Adapters\Horizon\HorizonJobRepositoryAdapter;
use Admnio\Sunset\Contracts\FailedJobRepository as SunsetFailedRepo;
use Admnio\Sunset\Contracts\JobRepository as SunsetJobRepo;
use Admnio\Sunset\JobPayload as SunsetJobPayload;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Support\Collection;
use Laravel\Horizon\JobPayload as HorizonJobPayload;
use Mockery;
use RuntimeException;

class HorizonJobRepositoryAdapterTest extends TestCase
{
    public function test_pushed_unwraps_horizon_payload_and_delegates_to_sunset_repo(): void
    {
        $jobs = Mockery::mock(SunsetJobRepo::class);
        $failed = Mockery::mock(SunsetFailedRepo::class);

        $jobs->shouldReceive('pushed')
            ->once()
            ->withArgs(function ($connection, $queue, $payload) {
                return $connection === 'sqs'
                    && $queue === 'orders'
                    && $payload instanceof SunsetJobPayload
                    && $payload->id() === 'abc';
            });

        $adapter = new HorizonJobRepositoryAdapter($jobs, $failed);
        $adapter->pushed('sqs', 'orders', new HorizonJobPayload(json_encode(['uuid' => 'abc'])));
    }

    public function test_reserved_released_completed_delegate_to_jobs_repo(): void
    {
        $jobs = Mockery::mock(SunsetJobRepo::class);
        $failed = Mockery::mock(SunsetFailedRepo::class);
        $hp = new HorizonJobPayload(json_encode(['uuid' => 'x']));

        $jobs->shouldReceive('reserved')->once()->withArgs(fn ($c, $q, $p) => $p instanceof SunsetJobPayload);
        $jobs->shouldReceive('released')->once()->withArgs(fn ($c, $q, $p, $d) => $d === 5);
        $jobs->shouldReceive('completed')->once()->withArgs(fn ($p, $s) => $s === true);

        $adapter = new HorizonJobRepositoryAdapter($jobs, $failed);
        $adapter->reserved('sqs', 'q', $hp);
        $adapter->released('sqs', 'q', $hp, 5);
        $adapter->completed($hp, failed: false, silenced: true);
    }

    public function test_failed_methods_delegate_to_failed_repo(): void
    {
        $jobs = Mockery::mock(SunsetJobRepo::class);
        $failed = Mockery::mock(SunsetFailedRepo::class);
        $hp = new HorizonJobPayload(json_encode(['uuid' => 'fail-1']));
        $ex = new RuntimeException('boom');

        $failed->shouldReceive('failed')->once()->withArgs(fn ($e, $c, $q, $p) =>
            $e === $ex && $p instanceof SunsetJobPayload && $p->id() === 'fail-1');
        $failed->shouldReceive('findFailed')->with('fail-1')->andReturn((object) ['id' => 'fail-1']);
        $failed->shouldReceive('countFailed')->andReturn(7);
        $failed->shouldReceive('totalFailed')->andReturn(7);
        $failed->shouldReceive('countRecentlyFailed')->andReturn(3);
        $failed->shouldReceive('deleteFailed')->with('fail-1')->andReturn(1);
        $failed->shouldReceive('trimFailedJobs')->once();
        $failed->shouldReceive('getFailed')->with(null)->andReturn(new Collection());

        $adapter = new HorizonJobRepositoryAdapter($jobs, $failed);
        $adapter->failed($ex, 'sqs', 'q', $hp);

        $this->assertSame('fail-1', $adapter->findFailed('fail-1')->id);
        $this->assertSame(7, $adapter->countFailed());
        $this->assertSame(7, $adapter->totalFailed());
        $this->assertSame(3, $adapter->countRecentlyFailed());
        $this->assertSame(1, $adapter->deleteFailed('fail-1'));
        $adapter->trimFailedJobs();
        $this->assertInstanceOf(Collection::class, $adapter->getFailed());
    }

    public function test_get_methods_delegate_to_sunset_jobs(): void
    {
        $jobs = Mockery::mock(SunsetJobRepo::class);
        $failed = Mockery::mock(SunsetFailedRepo::class);

        $jobs->shouldReceive('getRecent')->with(null)->andReturn(new Collection(['a']));
        $jobs->shouldReceive('getPending')->with(null)->andReturn(new Collection(['b']));
        $jobs->shouldReceive('getCompleted')->with(null)->andReturn(new Collection(['c']));
        $jobs->shouldReceive('getSilenced')->with(null)->andReturn(new Collection(['d']));
        $jobs->shouldReceive('countRecent')->andReturn(1);
        $jobs->shouldReceive('countPending')->andReturn(2);
        $jobs->shouldReceive('countCompleted')->andReturn(3);
        $jobs->shouldReceive('countSilenced')->andReturn(4);
        $jobs->shouldReceive('totalRecent')->andReturn(5);

        $adapter = new HorizonJobRepositoryAdapter($jobs, $failed);

        $this->assertSame(['a'], $adapter->getRecent()->all());
        $this->assertSame(['b'], $adapter->getPending()->all());
        $this->assertSame(['c'], $adapter->getCompleted()->all());
        $this->assertSame(['d'], $adapter->getSilenced()->all());
        $this->assertSame(1, $adapter->countRecent());
        $this->assertSame(2, $adapter->countPending());
        $this->assertSame(3, $adapter->countCompleted());
        $this->assertSame(4, $adapter->countSilenced());
        $this->assertSame(5, $adapter->totalRecent());
    }

    public function test_next_job_id_delegates(): void
    {
        $jobs = Mockery::mock(SunsetJobRepo::class);
        $failed = Mockery::mock(SunsetFailedRepo::class);
        $jobs->shouldReceive('nextJobId')->andReturn('42');

        $adapter = new HorizonJobRepositoryAdapter($jobs, $failed);
        $this->assertSame('42', $adapter->nextJobId());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
