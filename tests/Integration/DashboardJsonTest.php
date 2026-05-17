<?php

namespace MasonWorkforce\HorizonSqs\Tests\Integration;

use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Horizon;
use MasonWorkforce\HorizonSqs\Tests\Fixtures\Jobs\RecordingJob;

class DashboardJsonTest extends IntegrationTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Tell our ServiceProvider's resolveQueueList() which queues to query
        // by registering a supervisor for the current ('testing') environment.
        $app['config']->set('horizon.environments.testing.supervisor-1', [
            'connection' => 'sqs',
            'queue' => ['default'],
        ]);

        // Horizon's routes execute through the full HTTP kernel, which boots
        // the EncryptionServiceProvider and requires an app key.
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureLocalStackAvailable();
        $this->deleteAllQueues();
        $url = $this->createQueue('default');
        config(['queue.connections.sqs.prefix' => str_replace('/default', '', $url)]);

        // Bypass Horizon's "viewHorizon" gate which by default only allows
        // local environment access. Without this, /horizon/api/* returns 403.
        Horizon::auth(fn () => true);
    }

    public function test_workload_endpoint_returns_sqs_backed_data(): void
    {
        Queue::push(new RecordingJob('m1'));
        Queue::push(new RecordingJob('m2'));

        sleep(1); // SQS approximate count latency

        $response = $this->get('/horizon/api/workload');

        $response->assertOk();
        $data = $response->json();

        $default = collect($data)->firstWhere('name', 'default');
        $this->assertNotNull($default);
        $this->assertGreaterThanOrEqual(2, $default['length']);
        $this->assertArrayHasKey('wait', $default);
    }

    public function test_pending_jobs_endpoint_returns_pushed_job(): void
    {
        // Pre-clean Horizon's Redis state so prior test runs don't pollute counts.
        app(\Laravel\Horizon\Contracts\JobRepository::class)->trimRecentJobs();

        Queue::push(new RecordingJob('m1'));

        sleep(1);

        $response = $this->get('/horizon/api/jobs/pending');
        $response->assertOk();

        $body = $response->json();
        $this->assertArrayHasKey('jobs', $body);
        $this->assertArrayHasKey('total', $body);
        $this->assertGreaterThanOrEqual(
            1,
            $body['total'],
            'Expected at least one pending job recorded by Horizon after Queue::push',
        );
        $this->assertNotEmpty($body['jobs'], 'Expected pending jobs list to be non-empty');

        // Verify the JobPayload-derived fields propagated through StoreJob.
        $first = $body['jobs'][0];
        $this->assertNotEmpty($first['id'] ?? null);
        $this->assertSame('sqs', $first['connection'] ?? null);
        $this->assertSame('default', $first['queue'] ?? null);
    }

    public function test_dashboard_stats_recent_jobs_count_reflects_pushed_jobs(): void
    {
        app(\Laravel\Horizon\Contracts\JobRepository::class)->trimRecentJobs();

        Queue::push(new RecordingJob('m1'));

        sleep(1);

        $response = $this->get('/horizon/api/stats');
        $response->assertOk();

        $stats = $response->json();
        $this->assertArrayHasKey('recentJobs', $stats);
        $this->assertGreaterThanOrEqual(1, $stats['recentJobs']);
    }
}
