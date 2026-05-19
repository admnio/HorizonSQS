<?php

namespace Admnio\Sunset\Tests\Unit\Supervisor;

use Admnio\Sunset\Contracts\SupervisorCommandQueue;
use Admnio\Sunset\Contracts\SupervisorRepository;
use Admnio\Sunset\Supervisor\Supervisor;
use Admnio\Sunset\Supervisor\SupervisorFactory;
use Admnio\Sunset\Supervisor\SupervisorOptions;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class SupervisorFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_make_returns_a_supervisor_instance(): void
    {
        $this->bindDependencies();

        $options = new SupervisorOptions('sup-1', 'sqs', 'default');
        $factory = new SupervisorFactory();

        $supervisor = $factory->make($options);

        $this->assertInstanceOf(Supervisor::class, $supervisor);
    }

    public function test_make_sets_options_on_supervisor(): void
    {
        $this->bindDependencies();

        $options = new SupervisorOptions('sup-test', 'sqs', 'orders', balance: 'auto', maxProcesses: 7);
        $factory = new SupervisorFactory();

        $supervisor = $factory->make($options);

        $this->assertSame($options, $supervisor->options);
        $this->assertSame('sup-test', $supervisor->name);
    }

    public function test_make_returns_different_instances_per_call(): void
    {
        $this->bindDependencies();

        $factory = new SupervisorFactory();

        $sup1 = $factory->make(new SupervisorOptions('s1', 'sqs', 'default'));

        // Re-bind so the second constructor's flush succeeds
        $this->bindDependencies();

        $sup2 = $factory->make(new SupervisorOptions('s2', 'sqs', 'default'));

        $this->assertNotSame($sup1, $sup2);
    }

    private function bindDependencies(): void
    {
        $repo = Mockery::mock(SupervisorRepository::class);
        $repo->shouldReceive('find')->andReturn(null)->byDefault();
        $repo->shouldReceive('update')->byDefault();

        $queue = Mockery::mock(SupervisorCommandQueue::class);
        $queue->shouldReceive('flush')->byDefault();
        $queue->shouldReceive('pending')->andReturn([])->byDefault();

        $this->app->instance(SupervisorRepository::class, $repo);
        $this->app->instance(SupervisorCommandQueue::class, $queue);
    }
}
