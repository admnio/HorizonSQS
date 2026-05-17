# horizon-sqs

Laravel Horizon for Amazon SQS — same dashboard, same metrics, SQS underneath.

## Why

Laravel Horizon is built for Redis queues. This package makes Horizon's dashboard work when your transport is SQS, using Redis only as a stats sidecar (Horizon's existing repositories, unchanged).

## What works

- Throughput metrics (jobs/min, jobs/hour)
- Recent / Failed / Completed jobs lists with payloads + retry
- Workload page (pending counts + estimated wait time)
- Tags and Monitored tags
- Job batches
- Retry from dashboard
- FIFO queues (standard + FIFO)
- S3 spill-over for payloads > 256 KB (opt-in)
- Long delays (>15 min) buffered in Redis, swept into SQS

## Not yet in v0.1.0

- In-worker visibility-timeout heartbeat (config knob reserved; planned for v0.2).

## Install

```bash
composer require masonworkforce/horizon-sqs
php artisan vendor:publish --tag=horizon-sqs-config
```

## Configure

`config/queue.php` — your `sqs` connection stays the same.

`config/horizon-sqs.php`:

```php
return [
    'redis_connection' => env('HORIZON_SQS_REDIS', 'default'),
    'workload_cache_ttl' => 5,
    'sqs_max_delay' => 900,
    'long_delay_sweep_interval' => 60,
    'visibility_heartbeat' => false,
    'fifo' => [
        'message_group_id' => 'queue-name', // 'queue-name' | 'job-class' | callable
        'content_based_dedup' => true,
    ],
    'extended_payload' => [
        'enabled' => false,
        'bucket' => env('HORIZON_SQS_S3_BUCKET'),
        'prefix' => 'horizon-sqs-payloads/',
        'lifecycle_days' => 7,
    ],
];
```

`config/horizon.php` — set your supervisor connection to `sqs`.

## Operational notes

- **Visibility timeout:** set on each SQS queue to ≥ your job `timeout` × 1.5. (The `visibility_heartbeat` config knob is reserved for v0.2 — currently ignored.)
- **Extended payload cleanup:** add a lifecycle rule to your S3 bucket prefix (default `horizon-sqs-payloads/`) to clean up orphans from worker crashes.
- **Long-delay sweep:** auto-registered via Laravel's scheduler. Ensure `schedule:run` is wired in your cron.

## Testing locally

```bash
docker compose up -d
vendor/bin/phpunit
```

## License

MIT.
