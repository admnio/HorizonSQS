<?php

namespace Admnio\Sunset\Tests\Integration;

/**
 * Scaffold for the end-to-end DLX routing test.
 *
 * v0.6.0 lands the RabbitMQ transport and honors the `sunset.transports.rabbitmq
 * .dead_letter` config block at the workload/queue level, but the actual nack
 * path that routes a job into the dead-letter exchange is gated by the
 * rate-limit "drop" strategy, which doesn't exist yet. That work lands in
 * v0.7.0 (rate limits).
 *
 * Once v0.7.0 introduces RateLimitGate with `dropAsFailure(false)`, this test
 * class's single test method should be filled in to exercise the full path:
 * dispatch a job, have the gate drop it, observe the nack-without-requeue, and
 * assert the job lands in the configured dead-letter queue.
 *
 * Until then, the test is explicitly skipped so the v0.6.0 suite stays green
 * while we still ship the scaffold (so v0.7.0 only has to write the body).
 */
class RabbitDlxTest extends IntegrationTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sunset.transports.rabbitmq.dead_letter.enabled', true);
        $app['config']->set('sunset.transports.rabbitmq.dead_letter.exchange', 'sunset.test.dlx');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRabbitMQAvailable();
    }

    public function test_nacked_job_lands_in_dlx_queue(): void
    {
        $this->markTestSkipped(
            "This test exercises end-to-end DLX routing: a job that the rate limit gate\n"
            . "drops with the 'drop' strategy + dropAsFailure(false) must be nacked without\n"
            . "requeue and land in the configured dead-letter queue.\n\n"
            . "The drop-strategy nack path is implemented in v0.7.0 (rate limits). Re-enable\n"
            . "this test as part of v0.7.0's integration suite once both pieces are landed.\n\n"
            . "Tracking: v0.6.0 DLX config is honored at the workload level; nack-on-drop\n"
            . "routing requires v0.7.0 RateLimitGate work."
        );
    }
}
