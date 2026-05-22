<?php

namespace Admnio\Sunset\Tests\Integration\Dashboard;

use Admnio\Sunset\Contracts\SupervisorCommandQueue;
use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\Manager;
use Admnio\Sunset\SupervisorCommands\Scale;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * Drive the v2.3.0 supervisor scaling endpoint end-to-end against real Redis
 * and assert it pushes a Scale command (FQCN, not a tagged shape) onto the
 * supervisor's command queue with the requested target — so the supervisor's
 * next loop tick adjusts worker count without restarting.
 *
 * Mirrors DestructiveActionsTest::test_pause_supervisor_pushes_command — the
 * surface area is intentionally identical so future contract drift is loud.
 */
class SupervisorScaleTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Manager::flushAuth();
        Sunset::auth(fn () => true);

        // Wipe Sunset state so each test starts from a known baseline.
        $conn = $this->app->make(RedisFactory::class)
            ->connection(config('sunset.redis_connection', 'default'));
        foreach ((array) $conn->keys('sunset:*') as $k) {
            $conn->del($k);
        }
    }

    public function test_scale_supervisor_pushes_command_with_processes_option(): void
    {
        $commands = $this->app->make(SupervisorCommandQueue::class);

        $response = $this->postJson('/sunset/supervisors/test-supervisor/scale', [
            'processes' => 12,
        ]);
        $response->assertStatus(200)->assertJson([
            'ok'         => true,
            'command'    => 'scale',
            'supervisor' => 'test-supervisor',
            'processes'  => 12,
        ]);

        $pending = $commands->pending('test-supervisor');
        $this->assertNotEmpty($pending, 'A Scale command should be pending for the supervisor');

        // Verify both the FQCN dispatch key AND the processes option survive
        // the JSON round-trip through Redis — the supervisor reads both.
        $entries = $this->normalizedPending($pending);
        $this->assertContains(Scale::class, array_column($entries, 'command'));

        $scaleEntry = collect($entries)->firstWhere('command', Scale::class);
        $this->assertSame(12, (int) ($scaleEntry['options']['processes'] ?? 0));
    }

    public function test_scale_clamps_high_values_to_ceiling(): void
    {
        $commands = $this->app->make(SupervisorCommandQueue::class);

        $response = $this->postJson('/sunset/supervisors/test-supervisor/scale', [
            'processes' => 9999,
        ]);
        $response->assertStatus(200)->assertJson(['processes' => 256]);

        $entries = $this->normalizedPending($commands->pending('test-supervisor'));
        $scaleEntry = collect($entries)->firstWhere('command', Scale::class);
        $this->assertSame(256, (int) ($scaleEntry['options']['processes'] ?? 0));
    }

    public function test_scale_clamps_non_positive_values_to_one(): void
    {
        $commands = $this->app->make(SupervisorCommandQueue::class);

        // Operators must pause(), not scale to 0. The controller clamps 0 / negative / missing
        // payloads to 1 so a misclick can't take the queue offline silently.
        foreach ([0, -5, null] as $bad) {
            $payload = $bad === null ? [] : ['processes' => $bad];

            $response = $this->postJson('/sunset/supervisors/test-supervisor/scale', $payload);
            $response->assertStatus(200)->assertJson(['processes' => 1]);

            $commands->flush('test-supervisor');
        }
    }

    public function test_scale_command_dispatches_to_supervisor_scale_method(): void
    {
        // Belt-and-suspenders: even if the controller serializes the new
        // `processes` key, the supervisor must still understand it. We
        // also guard the legacy `scale` key here so older queued payloads
        // (from a pre-v2.3.0 producer that survived an upgrade) keep working.
        $supervisor = \Mockery::mock(\Admnio\Sunset\Supervisor\Supervisor::class);
        $supervisor->shouldReceive('scale')->with(7)->once();
        (new Scale())->process($supervisor, ['processes' => 7]);

        $legacy = \Mockery::mock(\Admnio\Sunset\Supervisor\Supervisor::class);
        $legacy->shouldReceive('scale')->with(3)->once();
        (new Scale())->process($legacy, ['scale' => 3]);

        // Non-positive must NOT dispatch — the supervisor's loop would crash
        // on a 0-process pool. Mockery::never() guards that contract.
        $noop = \Mockery::mock(\Admnio\Sunset\Supervisor\Supervisor::class);
        $noop->shouldReceive('scale')->never();
        (new Scale())->process($noop, ['processes' => 0]);
        (new Scale())->process($noop, []);
    }

    /**
     * Normalize SupervisorCommandQueue::pending() output to a list of
     * ['command' => FQCN, 'options' => [...]] arrays. Matches the helper in
     * DestructiveActionsTest so test code stays robust to whether the
     * contract returns objects or JSON-strings under the hood.
     *
     * @return array<int, array{command: string, options: array}>
     */
    private function normalizedPending(array $pending): array
    {
        $out = [];
        foreach ($pending as $entry) {
            if (is_string($entry)) {
                $decoded = json_decode($entry, true);
                $entry = is_array($decoded) ? $decoded : ['command' => $entry];
            }
            if (is_object($entry)) {
                $entry = (array) $entry;
            }
            $out[] = [
                'command' => (string) ($entry['command'] ?? ''),
                'options' => (array) ($entry['options'] ?? []),
            ];
        }

        return $out;
    }
}
