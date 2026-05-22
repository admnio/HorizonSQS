<?php

namespace Admnio\Sunset\Tests\Integration\Dashboard;

use Admnio\Sunset\Contracts\MetricsRepository;
use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\Manager;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * Asserts the v2.1 hero-stat aggregates (`throughput_per_min`,
 * `failure_rate_pct`, `failures_last_hour`) are present in the Overview
 * controller's prop bag — both via Inertia render and via the polling
 * `?refresh=1` JSON path.
 */
class OverviewAggregatesTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Manager::flushAuth();
        Sunset::auth(fn () => true);

        $this->wipeSunsetKeys();
    }

    private function wipeSunsetKeys(): void
    {
        try {
            $conn = $this->app->make(RedisFactory::class)
                ->connection(config('sunset.redis_connection', 'default'));
            $prefix = method_exists($conn, '_prefix') ? (string) $conn->_prefix('') : '';
            foreach ((array) $conn->keys('sunset:*') as $k) {
                $name = $prefix !== '' ? str_replace($prefix, '', $k) : $k;
                if ($prefix === '' && str_contains((string) $k, 'sunset:')) {
                    $name = substr((string) $k, strpos((string) $k, 'sunset:'));
                }
                $conn->del($name);
            }
        } catch (\Throwable) {
            // best-effort
        }
    }

    public function test_overview_refresh_payload_includes_aggregate_keys(): void
    {
        $response = $this->getJson('/sunset?refresh=1');
        $response->assertStatus(200);

        $props = $response->json('props');
        foreach (['throughput_per_min', 'failure_rate_pct', 'failures_last_hour'] as $key) {
            $this->assertArrayHasKey($key, $props, "Missing aggregate key: {$key}");
        }

        $this->assertIsString($props['throughput_per_min']);
        $this->assertIsString($props['failure_rate_pct']);
        $this->assertIsInt($props['failures_last_hour']);

        // No throughput and no failures -> dash for failure rate, 0 for count.
        $this->assertSame('—', $props['failure_rate_pct']);
        $this->assertSame(0, $props['failures_last_hour']);
        $this->assertSame('0', $props['throughput_per_min']);
    }

    public function test_overview_aggregates_reflect_recent_metrics_data(): void
    {
        /** @var MetricsRepository $metrics */
        $metrics = $this->app->make(MetricsRepository::class);
        $metrics->incrementThroughput('App\\Jobs\\X', 'default', 100.0);
        $metrics->incrementThroughput('App\\Jobs\\X', 'default', 100.0);
        $metrics->incrementThroughput('App\\Jobs\\X', 'default', 100.0);
        $metrics->snapshot();

        // Seed a failure into recent_failed_jobs so the rate becomes non-zero.
        $conn = $this->app->make(RedisFactory::class)
            ->connection(config('sunset.redis_connection', 'default'));
        $conn->zadd('sunset:recent_failed_jobs', microtime(true) * 1000, 'failure-1');

        $response = $this->getJson('/sunset?refresh=1');
        $response->assertStatus(200);

        $props = $response->json('props');
        $this->assertSame('3', $props['throughput_per_min']);
        $this->assertSame(1, $props['failures_last_hour']);
        // Rate = 1 / (3 + 1) * 100 = 25.00
        $this->assertSame('25.00', $props['failure_rate_pct']);
    }
}
