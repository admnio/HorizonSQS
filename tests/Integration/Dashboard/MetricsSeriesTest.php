<?php

namespace Admnio\Sunset\Tests\Integration\Dashboard;

use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\Manager;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * Smoke-tests the per-name series endpoints used by the Metrics dashboard
 * to render inline sparklines. Auth is forced permissive (or denied) by
 * resetting the static gate so this exercise focuses on controller wiring.
 */
class MetricsSeriesTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Manager::flushAuth();
        Sunset::auth(fn () => true);

        // Wipe sunset:* keys so a prior run's snapshots don't leak in.
        $conn = $this->app->make(RedisFactory::class)
            ->connection(config('sunset.redis_connection', 'default'));
        foreach ((array) $conn->keys('sunset:*') as $k) {
            $conn->del($k);
        }
    }

    public function test_job_series_endpoint_returns_points_array(): void
    {
        $response = $this->getJson('/sunset/metrics/jobs/' . urlencode('App\\Jobs\\Geocode'));

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertArrayHasKey('points', $data);
        $this->assertIsArray($data['points']);
        $this->assertArrayHasKey('snapshots', $data);
        $this->assertIsArray($data['snapshots']);
        $this->assertSame('App\\Jobs\\Geocode', $data['job']);
    }

    public function test_queue_series_endpoint_returns_points_array(): void
    {
        $response = $this->getJson('/sunset/metrics/queues/default');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertArrayHasKey('points', $data);
        $this->assertIsArray($data['points']);
        $this->assertArrayHasKey('snapshots', $data);
        $this->assertIsArray($data['snapshots']);
        $this->assertSame('default', $data['queue']);
    }

    public function test_points_are_normalized_zero_to_one(): void
    {
        // Seed a couple of throughput rows then snapshot to populate the
        // sorted set the controller reads. Two snapshots are taken with
        // different throughput counts so the normalized series has spread.
        $metrics = $this->app->make(\Admnio\Sunset\Contracts\MetricsRepository::class);

        $metrics->incrementThroughput('App\\Jobs\\Heavy', 'default', 0.5);
        $metrics->incrementThroughput('App\\Jobs\\Heavy', 'default', 0.5);
        $metrics->snapshot();

        $metrics->incrementThroughput('App\\Jobs\\Heavy', 'default', 0.5);
        $metrics->snapshot();

        $response = $this->getJson('/sunset/metrics/jobs/' . urlencode('App\\Jobs\\Heavy'));
        $response->assertStatus(200);
        $points = $response->json('points');

        $this->assertNotEmpty($points);
        foreach ($points as $p) {
            $this->assertGreaterThanOrEqual(0, $p);
            $this->assertLessThanOrEqual(1, $p);
        }
        // The max value in a normalized series is exactly 1.0.
        $this->assertSame(1.0, (float) max($points));
    }

    public function test_endpoints_are_authorized(): void
    {
        Manager::flushAuth();
        Sunset::auth(fn () => false);

        $this->getJson('/sunset/metrics/jobs/X')->assertStatus(403);
        $this->getJson('/sunset/metrics/queues/X')->assertStatus(403);
    }

    public function test_series_endpoint_returns_jobs_and_queues_batched(): void
    {
        $response = $this->getJson('/sunset/metrics/series?' . http_build_query([
            'jobs'   => ['App\\Jobs\\Foo', 'App\\Jobs\\Bar'],
            'queues' => ['default', 'high'],
        ]));

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('jobs', $data);
        $this->assertArrayHasKey('queues', $data);
        $this->assertIsArray($data['jobs']);
        $this->assertIsArray($data['queues']);
        // Each requested name should have a (possibly empty) points array.
        $this->assertArrayHasKey('App\\Jobs\\Foo', $data['jobs']);
        $this->assertArrayHasKey('default', $data['queues']);
    }

    public function test_series_endpoint_is_authorized(): void
    {
        Manager::flushAuth();
        Sunset::auth(fn () => false);

        $this->getJson('/sunset/metrics/series')->assertStatus(403);
    }

    public function test_series_endpoint_caps_input_size(): void
    {
        $bigList = array_map(fn ($i) => "Job{$i}", range(1, 200));
        $response = $this->getJson('/sunset/metrics/series?' . http_build_query([
            'jobs' => $bigList,
        ]));

        $response->assertStatus(200);
        // Only the first 100 should be processed.
        $this->assertCount(100, $response->json('jobs'));
    }
}
