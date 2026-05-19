<?php

namespace Admnio\Sunset\Tests\Unit\Support;

use Admnio\Sunset\Supervisor\SupervisorOptions;
use Admnio\Sunset\Support\WorkerCommandString;
use Admnio\Sunset\Tests\TestCase;

class WorkerCommandStringTest extends TestCase
{
    public function test_command_string_starts_with_sunset_worker_and_includes_connection(): void
    {
        $options = new SupervisorOptions(
            name: 'master-1:supervisor-1',
            connection: 'sqs',
            queue: 'default',
            workersName: 'workers',
            memory: 256,
            timeout: 90,
            maxTries: 3,
        );

        $command = WorkerCommandString::fromOptions($options);

        $this->assertStringContainsString('sunset:worker', $command);
        $this->assertStringContainsString("sqs", $command);
        $this->assertStringContainsString('--name=workers', $command);
        $this->assertStringContainsString('--memory=256', $command);
        $this->assertStringContainsString('--timeout=90', $command);
        $this->assertStringContainsString('--tries=3', $command);
        $this->assertStringContainsString('--supervisor=', $command);
    }
}
