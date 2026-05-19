<?php

namespace Admnio\Sunset\Tests\Unit\Supervisor;

use Admnio\Sunset\Contracts\SupervisorCommandQueue;
use Admnio\Sunset\Contracts\SupervisorRepository;
use Admnio\Sunset\Supervisor\SupervisorOptions;
use Admnio\Sunset\Supervisor\SupervisorProcess;
use Admnio\Sunset\Tests\TestCase;
use Mockery;
use Symfony\Component\Process\Process;

class SupervisorProcessTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_constructor_sets_name_and_options(): void
    {
        $options = new SupervisorOptions('sup-1', 'sqs', 'default');
        $process = $this->makeProcess();

        $sp = new SupervisorProcess($options, $process);

        $this->assertSame('sup-1', $sp->name);
        $this->assertSame($options, $sp->options);
        $this->assertFalse($sp->dead);
    }

    public function test_constructor_uses_noop_output_when_none_provided(): void
    {
        $options = new SupervisorOptions('sup-1', 'sqs', 'default');
        $process = $this->makeProcess();

        $sp = new SupervisorProcess($options, $process);

        $this->assertIsCallable($sp->output);
    }

    public function test_constructor_accepts_custom_output_callback(): void
    {
        $options = new SupervisorOptions('sup-1', 'sqs', 'default');
        $process = $this->makeProcess();
        $output = fn ($type, $line) => null;

        $sp = new SupervisorProcess($options, $process, $output);

        $this->assertSame($output, $sp->output);
    }

    public function test_mark_as_dead_sets_dead_flag(): void
    {
        $sp = $this->makeSupervisorProcess();

        $this->assertFalse($sp->dead);
        $sp->exposedMarkAsDead();
        $this->assertTrue($sp->dead);
    }

    public function test_monitor_returns_early_when_not_started(): void
    {
        $process = $this->makeProcess(['isStarted' => false]);
        $sp = $this->makeSupervisorProcess(process: $process, outputCapture: $output);

        // Should try to restart (call start) — but since it wasn't started, it just starts fresh
        $sp->monitor();

        // After restart attempt, dead should still be false (not a dead-signal exit)
        $this->assertFalse($sp->dead);
    }

    public function test_monitor_marks_dead_on_exit_code_13(): void
    {
        $process = $this->makeProcess([
            'isStarted' => true,
            'isRunning' => false,
            'getExitCode' => 13,
        ]);
        $sp = $this->makeSupervisorProcess(process: $process);

        $sp->monitor();

        $this->assertTrue($sp->dead);
    }

    public function test_monitor_does_nothing_when_process_is_running(): void
    {
        $process = $this->makeProcess([
            'isStarted' => true,
            'isRunning' => true,
        ]);
        $sp = $this->makeSupervisorProcess(process: $process);

        $sp->monitor();

        $this->assertFalse($sp->dead);
    }

    public function test_monitor_marks_dead_when_stopped_with_dont_restart_code(): void
    {
        $process = $this->makeProcess([
            'isStarted' => true,
            'isRunning' => false,
            'getExitCode' => 0,   // exit 0 is in dontRestartOn
        ]);
        $sp = $this->makeSupervisorProcess(process: $process);

        $sp->monitor();

        $this->assertTrue($sp->dead);
    }

    public function test_terminate_with_status_pushes_to_command_queue(): void
    {
        $options = new SupervisorOptions('sup-1', 'sqs', 'default');
        $process = $this->makeProcess();

        $commandQueue = Mockery::mock(SupervisorCommandQueue::class);
        $commandQueue->shouldReceive('push')
            ->once()
            ->withArgs(function ($name, $command, $opts) {
                return $name === 'sup-1' && $opts === ['status' => 5];
            });

        $this->app->instance(SupervisorCommandQueue::class, $commandQueue);

        $sp = new SupervisorProcess($options, $process);
        $sp->terminateWithStatus(5);

        // Mockery will assert ->once() on close; add an explicit assertion to avoid risky flag.
        $this->assertTrue(true);
    }

    /**
     * Build a mock Symfony Process with sensible defaults.
     *
     * @param  array  $overrides  Map of method → return value
     * @return \Mockery\MockInterface
     */
    private function makeProcess(array $overrides = []): object
    {
        $defaults = [
            'isStarted' => true,
            'isRunning' => false,
            'getExitCode' => 1,
            'start' => null,
            'signal' => true,
            'stop' => 0,
        ];

        $config = array_merge($defaults, $overrides);
        $mock = Mockery::mock(Process::class);

        foreach ($config as $method => $return) {
            $mock->shouldReceive($method)->andReturn($return)->byDefault();
        }

        return $mock;
    }

    /**
     * Build a SupervisorProcess with an overridable expose for protected methods.
     */
    private function makeSupervisorProcess(
        ?SupervisorOptions $options = null,
        ?object $process = null,
        mixed &$outputCapture = null,
    ): object {
        $options ??= new SupervisorOptions('sup-1', 'sqs', 'default');
        $process ??= $this->makeProcess();

        return new class($options, $process) extends SupervisorProcess {
            public function exposedMarkAsDead(): void
            {
                $this->markAsDead();
            }
        };
    }
}
