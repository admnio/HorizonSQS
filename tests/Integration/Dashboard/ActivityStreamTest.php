<?php

namespace Admnio\Sunset\Tests\Integration\Dashboard;

use Admnio\Sunset\Activity\ActivityEvent;
use Admnio\Sunset\Activity\ActivityStreamer;
use Admnio\Sunset\Contracts\ActivityRepository;
use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\Manager;
use Admnio\Sunset\Repositories\Redis\RedisActivityRepository;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * Covers the GET /sunset/activity/stream branch of the ActivityController:
 *   - Correct SSE headers on a real StreamedResponse.
 *   - 404 when the activity feature is disabled in config.
 *   - The streamed body actually flushes SSE frames built from the buffered
 *     events. We bind a fake streamer in setUp() for the body-flush test so
 *     the response returns in milliseconds rather than max_connection_seconds.
 */
class ActivityStreamTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Manager::flushAuth();
        Sunset::auth(fn () => true);

        $this->app->make(RedisFactory::class)
            ->connection(config('sunset.redis_connection', 'default'))
            ->flushdb();

        config(['sunset.activity.enabled' => true]);
    }

    public function test_stream_response_headers_are_correct(): void
    {
        $this->bindFakeStreamer();

        $response = $this->get('/sunset/activity/stream');
        $response->assertStatus(200);

        $this->assertStringContainsString(
            'text/event-stream',
            $response->headers->get('Content-Type'),
        );

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-cache', $cacheControl);

        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
        $this->assertSame('close', $response->headers->get('Connection'));
    }

    public function test_stream_returns_404_when_activity_is_disabled(): void
    {
        config(['sunset.activity.enabled' => false]);

        $response = $this->get('/sunset/activity/stream');
        $response->assertStatus(404);
    }

    public function test_stream_body_contains_sse_frames_for_buffered_events(): void
    {
        $this->bindFakeStreamer();
        $this->seedTwoEvents();

        // Capture the streamed body. Laravel's TestResponse#streamedContent()
        // sends the StreamedResponse callback and grabs the output buffer.
        $response = $this->get('/sunset/activity/stream', ['Last-Event-ID' => '0']);
        $response->assertStatus(200);

        $body = $response->streamedContent();

        // Both events should appear as SSE frames with `id: N` lines.
        $this->assertStringContainsString("id: 1\n", $body);
        $this->assertStringContainsString("id: 2\n", $body);
        // The frame should include the event type as the SSE `event:` field.
        $this->assertStringContainsString('event: job_failed', $body);
        $this->assertStringContainsString('event: job_completed', $body);
    }

    /**
     * Swap the streamer binding for a fake that does one repository->since()
     * read and exits — no clock, no sleep. Keeps the body-streaming test in
     * the millisecond range instead of waiting for max_connection_seconds.
     *
     * Bound on the container rather than via a setter so the controller's
     * `app(ActivityStreamer::class)` resolution picks up the fake without
     * any other rewiring.
     */
    private function bindFakeStreamer(): void
    {
        $this->app->bind(ActivityStreamer::class, function ($app) {
            // Closure-shared clock so sleep() can advance it. heartbeat
            // interval set well above max so a heartbeat doesn't get
            // interleaved into the body and confuse the assertions.
            $clock = 0.0;
            return new ActivityStreamer(
                repository: $app->make(ActivityRepository::class),
                maxConnectionSeconds: 1,
                heartbeatIntervalSeconds: 3600,
                pollIntervalSeconds: 1,
                clock: function () use (&$clock) {
                    return $clock;
                },
                sleep: function (int $seconds) use (&$clock) {
                    $clock += $seconds;
                },
                emit: static function (string $frame): void {
                    echo $frame;
                },
            );
        });
    }

    private function seedTwoEvents(): void
    {
        /** @var RedisActivityRepository $repo */
        $repo = $this->app->make(RedisActivityRepository::class);
        $repo->record(new ActivityEvent(
            id: 0,
            type: 'job_failed',
            occurredAt: 1_700_000_001,
            payload: ['job_id' => 'a'],
        ));
        $repo->record(new ActivityEvent(
            id: 0,
            type: 'job_completed',
            occurredAt: 1_700_000_002,
            payload: ['job_id' => 'b'],
        ));
    }
}
