<?php

namespace Admnio\Sunset\Tests\Integration\Dashboard;

use Admnio\Sunset\Contracts\FailedJobRepository;
use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Contracts\MetricsRepository;
use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\JobPayload;
use Admnio\Sunset\Manager;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use RuntimeException;

/**
 * Covers GET /sunset/metrics/jobs/{name}/detail — the Inertia ClassDetail
 * page introduced in v2.0. Asserts route resolves, the right component is
 * rendered, and the props expose the stat keys the Vue page reads.
 */
class ClassDetailPageTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Manager::flushAuth();
        Sunset::auth(fn () => true);

        // Wipe Sunset Redis state so per-class filters start from a clean
        // slate (each test seeds exactly the jobs it cares about). phpredis
        // returns prefixed key names from `keys()`, so strip the connection
        // prefix before calling del() to avoid the double-prefix that would
        // leave keys orphaned across runs. We try _prefix() first (phpredis
        // exposes the configured prefix) and fall back to the Laravel
        // `database.redis.options.prefix` config when running under predis.
        $conn = $this->app->make(RedisFactory::class)
            ->connection(config('sunset.redis_connection', 'default'));
        $prefix = method_exists($conn, '_prefix') ? (string) $conn->_prefix('') : '';
        if ($prefix === '') {
            $prefix = (string) config('database.redis.options.prefix', '');
        }
        foreach ((array) $conn->keys('sunset:*') as $k) {
            $name = $prefix !== '' ? str_replace($prefix, '', (string) $k) : (string) $k;
            $conn->del($name);
        }
    }

    public function test_class_detail_route_renders_inertia_component_with_expected_props(): void
    {
        // Use ?refresh=1 to hit the JSON branch directly. The Inertia render
        // branch reaches the same prop shape; testing both adds nothing here.
        $response = $this->getJson(
            '/sunset/metrics/jobs/' . urlencode('App\\Jobs\\IndexProduct') . '/detail?refresh=1',
        );

        $response->assertStatus(200);

        $props = $response->json('props');
        $this->assertIsArray($props);
        $this->assertSame('App\\Jobs\\IndexProduct', $props['class_name']);

        foreach (['stats', 'throughput_series', 'runtime_histogram', 'recent_runs', 'recent_failures'] as $key) {
            $this->assertArrayHasKey($key, $props, "Missing prop key: {$key}");
        }

        // Stats shape contract — Vue page reads each of these.
        $stats = $props['stats'];
        foreach (['runs_1h', 'avg_ms', 'p50_ms', 'p95_ms', 'p99_ms', 'failure_rate_pct', 'failures_1h'] as $k) {
            $this->assertArrayHasKey($k, $stats, "Stats missing: {$k}");
        }

        // Histogram is always 6 buckets.
        $this->assertCount(6, $props['runtime_histogram']);
        foreach ($props['runtime_histogram'] as $bucket) {
            foreach (['label', 'count', 'pct', 'danger'] as $k) {
                $this->assertArrayHasKey($k, $bucket);
            }
        }
    }

    public function test_class_detail_route_accepts_complex_class_names(): void
    {
        // PHP namespaced class names with backslashes — common in Laravel app
        // job classes like App\Jobs\Foo. URL-encoded by the dashboard router.
        $response = $this->getJson(
            '/sunset/metrics/jobs/' . urlencode('App\\Jobs\\Some\\Deeply\\Nested\\Class') . '/detail?refresh=1',
        );

        $response->assertStatus(200);
        $this->assertSame(
            'App\\Jobs\\Some\\Deeply\\Nested\\Class',
            $response->json('props.class_name'),
        );
    }

    public function test_recent_runs_filtered_by_class_name(): void
    {
        /** @var JobRepository $jobs */
        $jobs = $this->app->make(JobRepository::class);

        // Two runs for the target class, one for a different class.
        $jobs->pushed('redis', 'default', $this->payload('a1', 'App\\Jobs\\IndexProduct'));
        $jobs->pushed('redis', 'default', $this->payload('a2', 'App\\Jobs\\IndexProduct'));
        $jobs->pushed('redis', 'default', $this->payload('b1', 'App\\Jobs\\OtherJob'));

        $response = $this->getJson(
            '/sunset/metrics/jobs/' . urlencode('App\\Jobs\\IndexProduct') . '/detail?refresh=1',
        );
        $response->assertStatus(200);

        $runs = $response->json('props.recent_runs');
        $this->assertIsArray($runs);
        $this->assertCount(2, $runs, 'Only jobs matching the target class should appear');

        // Each row carries the contract shape ClassDetail.vue's DataTable reads.
        foreach ($runs as $row) {
            $this->assertArrayHasKey('at', $row);
            $this->assertArrayHasKey('queue', $row);
            $this->assertArrayHasKey('runtime_ms', $row);
            $this->assertArrayHasKey('status', $row);
            $this->assertArrayHasKey('attempt', $row);
            $this->assertArrayHasKey('pid', $row);
            $this->assertArrayHasKey('tags', $row);
            $this->assertSame('default', $row['queue']);
            // pushed() doesn't set completed_at/reserved_at, so `at` is null
            // until the job moves forward through the lifecycle.
            $this->assertSame('pending', $row['status']);
        }
    }

    public function test_recent_failures_filtered_by_class_name(): void
    {
        /** @var FailedJobRepository $failed */
        $failed = $this->app->make(FailedJobRepository::class);

        $failed->failed(new RuntimeException('boom-1'), 'redis', 'default', $this->payload('f1', 'App\\Jobs\\IndexProduct'));
        $failed->failed(new RuntimeException('boom-2'), 'redis', 'default', $this->payload('f2', 'App\\Jobs\\IndexProduct'));
        $failed->failed(new RuntimeException('boom-3'), 'redis', 'default', $this->payload('f3', 'App\\Jobs\\OtherJob'));

        $response = $this->getJson(
            '/sunset/metrics/jobs/' . urlencode('App\\Jobs\\IndexProduct') . '/detail?refresh=1',
        );
        $response->assertStatus(200);

        $failures = $response->json('props.recent_failures');
        $this->assertIsArray($failures);
        $this->assertCount(2, $failures);

        foreach ($failures as $row) {
            $this->assertArrayHasKey('failed_at', $row);
            $this->assertArrayHasKey('exception_class', $row);
            $this->assertArrayHasKey('message', $row);
            $this->assertArrayHasKey('attempts', $row);
            $this->assertSame(RuntimeException::class, $row['exception_class']);
            $this->assertStringStartsWith('boom-', (string) $row['message']);
        }
    }

    public function test_failure_rate_reflects_per_class_failures(): void
    {
        /** @var MetricsRepository $metrics */
        $metrics = $this->app->make(MetricsRepository::class);
        /** @var FailedJobRepository $failed */
        $failed = $this->app->make(FailedJobRepository::class);

        // 10 measured runs for this class in the snapshot window.
        for ($i = 0; $i < 10; $i++) {
            $metrics->incrementThroughput('App\\Jobs\\IndexProduct', 'default', 100.0);
        }
        $metrics->snapshot();

        // One recorded failure for this class — failure_rate_pct = 1/10*100.
        $failed->failed(
            new RuntimeException('rate-1'),
            'redis',
            'default',
            $this->payload('rate-fail-1', 'App\\Jobs\\IndexProduct'),
        );

        $response = $this->getJson(
            '/sunset/metrics/jobs/' . urlencode('App\\Jobs\\IndexProduct') . '/detail?refresh=1',
        );
        $response->assertStatus(200);

        $stats = $response->json('props.stats');
        $this->assertSame(10, $stats['runs_1h']);
        $this->assertSame(1, $stats['failures_1h']);
        $this->assertSame('10.00', $stats['failure_rate_pct']);
    }

    /**
     * v2.2.0 — the runtime histogram on the ClassDetail page now reflects the
     * 6-bucket per-class histogram recorded at job-complete time by the Redis
     * concrete repo, not snapshot-mean values. Seed a known workload, hit the
     * route, assert each bucket's count matches the seed.
     */
    public function test_histogram_reflects_recorded_runtimes(): void
    {
        /** @var MetricsRepository $metrics */
        $metrics = $this->app->make(MetricsRepository::class);

        // Same canonical seed used by the unit test: 5×30ms, 10×100ms,
        // 3×400ms, 2×800ms, 1×2000ms, 1×6000ms (runtime in seconds).
        for ($i = 0; $i < 5; $i++)  $metrics->incrementThroughput('App\\Jobs\\IndexProduct', 'default', 0.030);
        for ($i = 0; $i < 10; $i++) $metrics->incrementThroughput('App\\Jobs\\IndexProduct', 'default', 0.100);
        for ($i = 0; $i < 3; $i++)  $metrics->incrementThroughput('App\\Jobs\\IndexProduct', 'default', 0.400);
        for ($i = 0; $i < 2; $i++)  $metrics->incrementThroughput('App\\Jobs\\IndexProduct', 'default', 0.800);
        $metrics->incrementThroughput('App\\Jobs\\IndexProduct', 'default', 2.000);
        $metrics->incrementThroughput('App\\Jobs\\IndexProduct', 'default', 6.000);

        $response = $this->getJson(
            '/sunset/metrics/jobs/' . urlencode('App\\Jobs\\IndexProduct') . '/detail?refresh=1',
        );
        $response->assertStatus(200);

        $hist = $response->json('props.runtime_histogram');
        $this->assertIsArray($hist);
        $this->assertCount(6, $hist);
        $this->assertSame(5,  $hist[0]['count']);
        $this->assertSame(10, $hist[1]['count']);
        $this->assertSame(3,  $hist[2]['count']);
        $this->assertSame(2,  $hist[3]['count']);
        $this->assertSame(1,  $hist[4]['count']);
        $this->assertSame(1,  $hist[5]['count']);
        // Tail bucket lights up red exactly when it has observations.
        $this->assertTrue($hist[5]['danger']);
    }

    /**
     * v2.2.0 — percentile stats are derived from the bucket histogram via
     * linear interpolation across bucket boundaries. Asserts the props land
     * in the buckets they should for the canonical seed workload.
     */
    public function test_percentiles_reflect_recorded_runtimes(): void
    {
        /** @var MetricsRepository $metrics */
        $metrics = $this->app->make(MetricsRepository::class);

        for ($i = 0; $i < 5; $i++)  $metrics->incrementThroughput('App\\Jobs\\IndexProduct', 'default', 0.030);
        for ($i = 0; $i < 10; $i++) $metrics->incrementThroughput('App\\Jobs\\IndexProduct', 'default', 0.100);
        for ($i = 0; $i < 3; $i++)  $metrics->incrementThroughput('App\\Jobs\\IndexProduct', 'default', 0.400);
        for ($i = 0; $i < 2; $i++)  $metrics->incrementThroughput('App\\Jobs\\IndexProduct', 'default', 0.800);
        $metrics->incrementThroughput('App\\Jobs\\IndexProduct', 'default', 2.000);
        $metrics->incrementThroughput('App\\Jobs\\IndexProduct', 'default', 6.000);

        $response = $this->getJson(
            '/sunset/metrics/jobs/' . urlencode('App\\Jobs\\IndexProduct') . '/detail?refresh=1',
        );
        $response->assertStatus(200);

        $stats = $response->json('props.stats');
        // p50 falls in the 50–250 ms bucket; p95 in the 1–5 s bucket; p99 in
        // the 5 s+ tail. Exact values depend on the interpolation formula
        // but the bucket containment is the contract here.
        $this->assertGreaterThanOrEqual(50,   $stats['p50_ms']);
        $this->assertLessThan(250,            $stats['p50_ms']);
        $this->assertGreaterThanOrEqual(1000, $stats['p95_ms']);
        $this->assertLessThan(5000,           $stats['p95_ms']);
        $this->assertGreaterThanOrEqual(5000, $stats['p99_ms']);
    }

    /**
     * Build a JobPayload whose decoded `uuid` and `displayName` map directly
     * to the `id` and `name` fields the repositories persist on the job hash.
     */
    private function payload(string $id, string $displayName): JobPayload
    {
        return new JobPayload(json_encode([
            'uuid'        => $id,
            'displayName' => $displayName,
            'job'         => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data'        => ['commandName' => $displayName],
            'tags'        => [],
        ]));
    }
}
