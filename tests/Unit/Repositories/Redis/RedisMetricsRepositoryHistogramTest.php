<?php

namespace Admnio\Sunset\Tests\Unit\Repositories\Redis;

use Admnio\Sunset\Repositories\Redis\RedisMetricsRepository;
use Admnio\Sunset\Tests\TestCase;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * v2.2.0 — covers the per-class 6-bucket runtime histogram + interpolated
 * percentile APIs added to the concrete RedisMetricsRepository (not on the
 * MetricsRepository contract). These are the source of truth for the
 * ClassDetail page's stats tiles and histogram chart.
 *
 * @internal
 */
class RedisMetricsRepositoryHistogramTest extends TestCase
{
    private RedisMetricsRepository $repo;
    private $redis;

    protected function setUp(): void
    {
        parent::setUp();
        $factory = $this->app->make(RedisFactory::class);
        $this->redis = $factory->connection('default');
        // Strip the configured key prefix when reading back from phpredis'
        // keys() — phpredis returns prefixed names but expects unprefixed
        // names from del(). Matches the existing repo-test pattern.
        foreach ($this->redis->keys('sunset:*') as $key) {
            $name = str_replace($this->redis->_prefix(''), '', $key);
            $this->redis->del($name);
        }
        $this->repo = new RedisMetricsRepository($factory);
    }

    protected function tearDown(): void
    {
        // Tests using setTestNow() must reset the clock so other tests
        // observe real time. Carbon::setTestNow() is wall-of-shame global state.
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_runtime_buckets_for_job_returns_correct_counts_per_bucket(): void
    {
        $this->seedFooWorkload();

        $buckets = $this->repo->runtimeBucketsForJob('Foo');

        $this->assertCount(6, $buckets);
        // Counts: 5 jobs at 30ms → b0; 10 at 100ms → b1; 3 at 400ms → b2;
        // 2 at 800ms → b3; 1 at 2000ms → b4; 1 at 6000ms → b5.
        $this->assertSame(5,  $buckets[0]['count']);
        $this->assertSame(10, $buckets[1]['count']);
        $this->assertSame(3,  $buckets[2]['count']);
        $this->assertSame(2,  $buckets[3]['count']);
        $this->assertSame(1,  $buckets[4]['count']);
        $this->assertSame(1,  $buckets[5]['count']);
    }

    public function test_runtime_buckets_for_job_returns_expected_labels(): void
    {
        $this->seedFooWorkload();

        $buckets = $this->repo->runtimeBucketsForJob('Foo');

        // Labels match the ClassDetail page's expected format. Vue page reads
        // these directly, no further mapping in the controller.
        $this->assertSame('0–50 ms',    $buckets[0]['label']);
        $this->assertSame('50–250 ms',  $buckets[1]['label']);
        $this->assertSame('250–500 ms', $buckets[2]['label']);
        $this->assertSame('500 ms–1 s', $buckets[3]['label']);
        $this->assertSame('1–5 s',      $buckets[4]['label']);
        $this->assertSame('5 s+',       $buckets[5]['label']);
    }

    public function test_runtime_buckets_for_job_returns_sane_percentages(): void
    {
        $this->seedFooWorkload();

        $buckets = $this->repo->runtimeBucketsForJob('Foo');

        // Total observations: 5 + 10 + 3 + 2 + 1 + 1 = 22.
        $total = 22;
        $this->assertEqualsWithDelta(round(5 / $total * 100, 1),  $buckets[0]['pct'], 0.01);
        $this->assertEqualsWithDelta(round(10 / $total * 100, 1), $buckets[1]['pct'], 0.01);
        $this->assertEqualsWithDelta(round(3 / $total * 100, 1),  $buckets[2]['pct'], 0.01);
        $this->assertEqualsWithDelta(round(2 / $total * 100, 1),  $buckets[3]['pct'], 0.01);
        $this->assertEqualsWithDelta(round(1 / $total * 100, 1),  $buckets[4]['pct'], 0.01);
        $this->assertEqualsWithDelta(round(1 / $total * 100, 1),  $buckets[5]['pct'], 0.01);

        // The 5s+ tail bucket gets the danger flag exactly when it has data.
        $this->assertTrue($buckets[5]['danger']);
        $this->assertFalse($buckets[4]['danger']);
        $this->assertFalse($buckets[0]['danger']);
    }

    public function test_runtime_buckets_for_job_returns_zero_buckets_when_no_data(): void
    {
        $buckets = $this->repo->runtimeBucketsForJob('UnseenJob');

        $this->assertCount(6, $buckets);
        foreach ($buckets as $b) {
            $this->assertSame(0, $b['count']);
            $this->assertSame(0.0, $b['pct']);
            $this->assertFalse($b['danger']);
        }
    }

    public function test_percentiles_for_job_returns_p50_p95_p99_in_expected_buckets(): void
    {
        $this->seedFooWorkload();

        $pcts = $this->repo->percentilesForJob('Foo');

        $this->assertArrayHasKey('p50', $pcts);
        $this->assertArrayHasKey('p95', $pcts);
        $this->assertArrayHasKey('p99', $pcts);

        // Cumulative: b0=5 (22.7%), b1=15 (68.2%), b2=18 (81.8%), b3=20 (90.9%),
        // b4=21 (95.5%), b5=22 (100%).
        // p50 (target=11) lands in b1 (50-250ms range).
        $this->assertGreaterThanOrEqual(50,  $pcts['p50']);
        $this->assertLessThan(250,           $pcts['p50']);
        // p95 (target=20.9) lands in b4 (1-5s range).
        $this->assertGreaterThanOrEqual(1000, $pcts['p95']);
        $this->assertLessThan(5000,           $pcts['p95']);
        // p99 (target=21.78) lands in the 5s+ tail bucket.
        $this->assertGreaterThanOrEqual(5000, $pcts['p99']);
    }

    public function test_percentiles_for_job_returns_zeros_when_no_data(): void
    {
        $pcts = $this->repo->percentilesForJob('UnseenJob');

        $this->assertSame(['p50' => 0, 'p95' => 0, 'p99' => 0], $pcts);
    }

    public function test_forget_job_clears_bucket_hash(): void
    {
        $this->seedFooWorkload();

        // Sanity: bucket data is populated.
        $before = $this->repo->runtimeBucketsForJob('Foo');
        $this->assertGreaterThan(0, array_sum(array_column($before, 'count')));

        // Sanity: at least one slot hash exists under the v2.4 layout. We
        // SCAN the live keyspace instead of guessing the slot ID so the
        // assertion stays stable across the 5-minute boundary.
        $slotKeysBefore = $this->redis->keys('sunset:metrics:job:Foo:buckets:*');
        $this->assertNotEmpty($slotKeysBefore, 'expected at least one slot hash after seeding');

        $this->repo->forgetJob('Foo');

        // After forget, all buckets should be zeroed (slot hashes are
        // DEL'd and reads gracefully sum to zero across the window).
        $after = $this->repo->runtimeBucketsForJob('Foo');
        $this->assertSame(0, array_sum(array_column($after, 'count')));

        // Slot hashes are gone from Redis.
        $slotKeysAfter = $this->redis->keys('sunset:metrics:job:Foo:buckets:*');
        $this->assertSame([], $slotKeysAfter);

        // Legacy single-hash key (pre-v2.4) is also cleaned up if it ever
        // existed — forgetJob still DELs it for upgrade-path tidiness.
        $exists = (int) $this->redis->exists('sunset:metrics:job:Foo:buckets');
        $this->assertSame(0, $exists);
    }

    public function test_buckets_age_out_after_60_minutes(): void
    {
        // Anchor "now" at a fixed, well-inside-a-slot moment so the seeded
        // workload doesn't accidentally straddle two slots. We then advance
        // 70 minutes (past the 60-min window AND past the 70-min TTL slack)
        // before seeding a smaller workload that should be the ONLY data
        // {@see runtimeBucketsForJob()} surfaces.
        $t0 = CarbonImmutable::parse('2030-01-01 12:00:00');
        Carbon::setTestNow($t0);
        CarbonImmutable::setTestNow($t0);

        // Initial workload (22 jobs across all buckets).
        $this->seedFooWorkload();

        // Sanity: the rolling window currently sees the seeded data.
        $atT0 = $this->repo->runtimeBucketsForJob('Foo');
        $this->assertSame(22, array_sum(array_column($atT0, 'count')));

        // Time-travel forward 70 minutes. The slots written at t0 are now
        // older than the 60-minute window AND past their 70-minute TTL —
        // Redis will have evicted them. The reads below should see only
        // the post-jump writes.
        //
        // Carbon::setTestNow() does NOT propagate TTLs to a real Redis
        // server (Redis tracks wall clock independently), so we explicitly
        // sleep long enough for the keys to expire. To keep the test fast
        // we override the slot keys' TTL to 1 second AFTER the seed, then
        // wait 1.1 seconds. This validates aging behavior end-to-end
        // without making the test sleep for 70 minutes of real time.
        foreach ($this->redis->keys('sunset:metrics:job:Foo:buckets:*') as $key) {
            $name = str_replace($this->redis->_prefix(''), '', $key);
            $this->redis->expire($name, 1);
        }
        usleep(1_200_000); // 1.2s — give Redis time to expire the keys.

        $t1 = $t0->addMinutes(70);
        Carbon::setTestNow($t1);
        CarbonImmutable::setTestNow($t1);

        // New, distinct workload after the jump: 2 jobs at 100ms (→ b1).
        $this->repo->incrementThroughput('Foo', 'default', 0.100);
        $this->repo->incrementThroughput('Foo', 'default', 0.100);

        $atT1 = $this->repo->runtimeBucketsForJob('Foo');

        // Only the new jobs should be visible — old slot hashes expired.
        $this->assertSame(2, array_sum(array_column($atT1, 'count')));
        $this->assertSame(2, $atT1[1]['count'], 'new b1 jobs visible');
        $this->assertSame(0, $atT1[0]['count'], 'old b0 jobs aged out');
        $this->assertSame(0, $atT1[5]['count'], 'old b5 jobs aged out');
    }

    /**
     * Seed the canonical "Foo" workload used by the bucket + percentile tests:
     *
     *   5 jobs at 30ms   → b0 (0–50 ms)
     *  10 jobs at 100ms  → b1 (50–250 ms)
     *   3 jobs at 400ms  → b2 (250–500 ms)
     *   2 jobs at 800ms  → b3 (500 ms–1 s)
     *   1 job  at 2000ms → b4 (1–5 s)
     *   1 job  at 6000ms → b5 (5 s+)
     *
     * Runtime is passed in seconds (matching the MarkJobAsComplete contract).
     */
    private function seedFooWorkload(): void
    {
        for ($i = 0; $i < 5; $i++)  $this->repo->incrementThroughput('Foo', 'default', 0.030);
        for ($i = 0; $i < 10; $i++) $this->repo->incrementThroughput('Foo', 'default', 0.100);
        for ($i = 0; $i < 3; $i++)  $this->repo->incrementThroughput('Foo', 'default', 0.400);
        for ($i = 0; $i < 2; $i++)  $this->repo->incrementThroughput('Foo', 'default', 0.800);
        $this->repo->incrementThroughput('Foo', 'default', 2.000);
        $this->repo->incrementThroughput('Foo', 'default', 6.000);
    }
}
