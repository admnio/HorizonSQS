<?php

namespace Admnio\Sunset\Tests\Unit\Supervisor;

use Admnio\Sunset\Contracts\SupervisorCommandQueue;
use Admnio\Sunset\Supervisor\MasterSupervisor;
use Admnio\Sunset\Supervisor\ProvisioningPlan;
use Admnio\Sunset\Supervisor\SupervisorOptions;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class ProvisioningPlanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MasterSupervisor::resetToken();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Construction
    // -----------------------------------------------------------------------

    public function test_constructor_stores_master_name_and_plan(): void
    {
        $plan = new ProvisioningPlan('my-master', [
            'production' => [
                'supervisor-1' => [
                    'connection' => 'sqs',
                    'queue' => 'default',
                    'maxProcesses' => 3,
                ],
            ],
        ]);

        $this->assertSame('my-master', $plan->master);
        $this->assertArrayHasKey('production', $plan->plan);
    }

    public function test_constructor_parses_plan_into_supervisor_options(): void
    {
        $plan = new ProvisioningPlan('my-master', [
            'production' => [
                'supervisor-1' => [
                    'connection' => 'sqs',
                    'queue' => 'default',
                    'maxProcesses' => 5,
                ],
            ],
        ]);

        $parsed = $plan->parsed;

        $this->assertArrayHasKey('production', $parsed);
        $this->assertArrayHasKey('supervisor-1', $parsed['production']);
        $this->assertInstanceOf(SupervisorOptions::class, $parsed['production']['supervisor-1']);
        $this->assertSame(5, $parsed['production']['supervisor-1']->maxProcesses);
    }

    // -----------------------------------------------------------------------
    // get() factory method reads from config
    // -----------------------------------------------------------------------

    public function test_get_reads_from_sunset_config(): void
    {
        config()->set('sunset.supervisors', [
            'testing' => [
                'sup-1' => [
                    'connection' => 'sqs',
                    'queue' => 'default',
                    'maxProcesses' => 2,
                ],
            ],
        ]);
        config()->set('sunset.defaults', []);

        $plan = ProvisioningPlan::get('some-master');

        $this->assertInstanceOf(ProvisioningPlan::class, $plan);
        $this->assertSame('some-master', $plan->master);
        $this->assertArrayHasKey('testing', $plan->parsed);
    }

    // -----------------------------------------------------------------------
    // environments() / hasEnvironment()
    // -----------------------------------------------------------------------

    public function test_environments_returns_array_of_environment_names(): void
    {
        $plan = $this->makePlan();

        $environments = $plan->environments();

        $this->assertContains('production', $environments);
        $this->assertContains('staging', $environments);
    }

    public function test_has_environment_returns_true_for_existing(): void
    {
        $plan = $this->makePlan();

        $this->assertTrue($plan->hasEnvironment('production'));
        $this->assertFalse($plan->hasEnvironment('nonexistent'));
    }

    // -----------------------------------------------------------------------
    // optionsFor()
    // -----------------------------------------------------------------------

    public function test_options_for_returns_correct_supervisor_options(): void
    {
        $plan = $this->makePlan();

        $options = $plan->optionsFor('production', 'supervisor-1');

        $this->assertInstanceOf(SupervisorOptions::class, $options);
        $this->assertSame('sqs', $options->connection);
    }

    public function test_options_for_returns_null_for_unknown(): void
    {
        $plan = $this->makePlan();

        $this->assertNull($plan->optionsFor('production', 'nonexistent'));
        $this->assertNull($plan->optionsFor('unknown', 'supervisor-1'));
    }

    // -----------------------------------------------------------------------
    // default option merging
    // -----------------------------------------------------------------------

    public function test_defaults_are_applied_to_each_supervisor(): void
    {
        // Defaults are keyed by supervisor name, like the environment plans
        $plan = new ProvisioningPlan(
            'my-master',
            [
                'production' => [
                    'supervisor-1' => [
                        'connection' => 'sqs',
                        'queue' => 'default',
                        'maxProcesses' => 5,
                    ],
                ],
            ],
            [
                'supervisor-1' => ['connection' => 'sqs', 'queue' => 'default', 'memory' => 256],
            ]
        );

        $options = $plan->optionsFor('production', 'supervisor-1');

        $this->assertSame(256, $options->memory);
        $this->assertSame(5, $options->maxProcesses); // plan value wins over default
    }

    // -----------------------------------------------------------------------
    // convert() — key mapping (tries → maxTries, processes → maxProcesses)
    // -----------------------------------------------------------------------

    public function test_convert_maps_tries_to_max_tries(): void
    {
        $plan = new ProvisioningPlan('m', [
            'production' => [
                'sup' => ['connection' => 'sqs', 'queue' => 'default', 'tries' => 5],
            ],
        ]);

        $options = $plan->optionsFor('production', 'sup');

        $this->assertSame(5, $options->maxTries);
    }

    public function test_convert_maps_processes_to_max_processes(): void
    {
        $plan = new ProvisioningPlan('m', [
            'production' => [
                'sup' => ['connection' => 'sqs', 'queue' => 'default', 'processes' => 7],
            ],
        ]);

        $options = $plan->optionsFor('production', 'sup');

        $this->assertSame(7, $options->maxProcesses);
    }

    public function test_convert_joins_queue_array_with_comma(): void
    {
        $plan = new ProvisioningPlan('m', [
            'production' => [
                'sup' => ['connection' => 'sqs', 'queue' => ['orders', 'emails']],
            ],
        ]);

        $options = $plan->optionsFor('production', 'sup');

        $this->assertSame('orders,emails', $options->queue);
    }

    public function test_convert_throws_when_min_processes_less_than_one(): void
    {
        $this->expectException(\Exception::class);

        new ProvisioningPlan('m', [
            'production' => [
                'sup' => ['connection' => 'sqs', 'queue' => 'default', 'minProcesses' => 0],
            ],
        ]);
    }

    // -----------------------------------------------------------------------
    // deploy() — pushes AddSupervisor commands to the master queue
    // -----------------------------------------------------------------------

    public function test_deploy_pushes_add_supervisor_commands_for_matching_environment(): void
    {
        $commandQueue = Mockery::mock(SupervisorCommandQueue::class);
        $commandQueue->shouldReceive('push')
            ->twice()  // two supervisors in production
            ->withArgs(function ($name, $command, $options) {
                return str_starts_with($name, 'master:')
                    && $command === 'Admnio\Sunset\MasterSupervisorCommands\AddSupervisor';
            });

        $this->app->instance(SupervisorCommandQueue::class, $commandQueue);

        $plan = new ProvisioningPlan('my-master', [
            'production' => [
                'supervisor-1' => ['connection' => 'sqs', 'queue' => 'default', 'maxProcesses' => 3],
                'supervisor-2' => ['connection' => 'sqs', 'queue' => 'emails', 'maxProcesses' => 2],
            ],
        ]);

        $this->app['events']->listen(\Admnio\Sunset\Events\MasterSupervisorDeployed::class, function () {});

        $plan->deploy('production');

        $this->assertTrue(true);
    }

    public function test_deploy_does_not_push_when_max_processes_is_zero(): void
    {
        $commandQueue = Mockery::mock(SupervisorCommandQueue::class);
        $commandQueue->shouldReceive('push')->never();

        $this->app->instance(SupervisorCommandQueue::class, $commandQueue);

        $plan = new ProvisioningPlan('my-master', [
            'production' => [
                'supervisor-1' => ['connection' => 'sqs', 'queue' => 'default', 'maxProcesses' => 0],
            ],
        ]);

        $plan->deploy('production');

        $this->assertTrue(true);
    }

    public function test_deploy_does_nothing_for_unknown_environment(): void
    {
        $commandQueue = Mockery::mock(SupervisorCommandQueue::class);
        $commandQueue->shouldReceive('push')->never();

        $this->app->instance(SupervisorCommandQueue::class, $commandQueue);

        $plan = $this->makePlan();
        $plan->deploy('unknown-environment');

        $this->assertTrue(true);
    }

    public function test_deploy_dispatches_master_supervisor_deployed_event(): void
    {
        $commandQueue = Mockery::mock(SupervisorCommandQueue::class);
        $commandQueue->shouldReceive('push')->byDefault();

        $this->app->instance(SupervisorCommandQueue::class, $commandQueue);

        $fired = false;
        $this->app['events']->listen(
            \Admnio\Sunset\Events\MasterSupervisorDeployed::class,
            function ($event) use (&$fired) {
                $fired = true;
            }
        );

        $plan = $this->makePlan();
        $plan->deploy('production');

        $this->assertTrue($fired, 'MasterSupervisorDeployed event was not dispatched');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makePlan(): ProvisioningPlan
    {
        return new ProvisioningPlan('my-master', [
            'production' => [
                'supervisor-1' => ['connection' => 'sqs', 'queue' => 'default', 'maxProcesses' => 3],
                'supervisor-2' => ['connection' => 'sqs', 'queue' => 'emails', 'maxProcesses' => 2],
            ],
            'staging' => [
                'supervisor-1' => ['connection' => 'sqs', 'queue' => 'default', 'maxProcesses' => 1],
            ],
        ]);
    }
}
