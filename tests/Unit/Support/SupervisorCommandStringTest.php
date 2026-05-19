<?php

namespace Admnio\Sunset\Tests\Unit\Support;

use Admnio\Sunset\Supervisor\SupervisorOptions;
use Admnio\Sunset\Support\SupervisorCommandString;
use Admnio\Sunset\Tests\TestCase;

class SupervisorCommandStringTest extends TestCase
{
    public function test_command_string_starts_with_sunset_supervise_and_includes_name_connection(): void
    {
        $options = new SupervisorOptions(
            name: 'master-1:supervisor-1',
            connection: 'sqs',
            queue: 'default',
            balance: 'auto',
            maxProcesses: 10,
            minProcesses: 2,
            parentId: 999,
        );

        $command = SupervisorCommandString::fromOptions($options);

        $this->assertStringContainsString('sunset:supervise', $command);
        $this->assertStringContainsString("'master-1:supervisor-1'", $command);
        $this->assertStringContainsString("sqs", $command);
        $this->assertStringContainsString('--balance=auto', $command);
        $this->assertStringContainsString('--max-processes=10', $command);
        $this->assertStringContainsString('--min-processes=2', $command);
        $this->assertStringContainsString('--parent-id=999', $command);
    }

    public function test_command_string_includes_queue_when_set(): void
    {
        $options = new SupervisorOptions('s', 'sqs', queue: 'orders,emails');
        $command = SupervisorCommandString::fromOptions($options);
        $this->assertStringContainsString('--queue=', $command);
        $this->assertStringContainsString('orders,emails', $command);
    }
}
