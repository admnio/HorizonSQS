# Sunset for Laravel

Supercharged Laravel Horizon replacement. v0.2.0 ships the SQS transport.

## Why

Sunset is the foundation for a multi-transport Horizon replacement: SQS, Redis, BullMQ, RabbitMQ, and LavinMQ all behind one consistent dashboard with deeper visibility into workers and queues than Horizon offers. v0.2.0 is the rename and `Transport` abstraction; later releases add Redis, our own supervisor, our own repositories, our own dashboard SPA, worker resource monitoring, real-time visibility, and per-queue pause/resume controls.

This release ships:

- Full Laravel Horizon support for Amazon SQS — same dashboard, same metrics, SQS underneath.
- Throughput metrics (jobs/min, jobs/hour)
- Recent / Failed / Completed jobs lists with payloads + retry
- Workload page (pending counts + estimated wait time)
- Tags and Monitored tags
- Job batches
- Retry from dashboard
- FIFO queues (standard + FIFO)
- S3 spill-over for payloads > 256 KB (opt-in)
- Long delays (>15 min) buffered in Redis, swept into SQS
- Long polling on by default (max 20s WaitTimeSeconds — cheapest SQS setting)
- `Transport` interface so future drivers plug in without touching SQS code

## Not yet in v0.2.0 (planned)

- v0.3.0: Sunset's own Redis transport
- v0.4.0: `sunset:work` supervisor replacing `php artisan horizon`
- v0.5.0: Sunset repositories replacing Horizon's
- v1.0.0: Full SPA dashboard, drops `laravel/horizon` dependency
- v1.1.0: Worker CPU/Memory monitoring
- v1.2.0: Realtime worker activity stream
- v1.3.0: Queue pause/resume controls

## Install

```bash
composer require admnio/sunset
php artisan vendor:publish --tag=sunset-config
```

## Configure

`config/queue.php` — your `sqs` connection stays the same as standard Laravel.

`config/sunset.php`:

```php
return [
    'redis_connection' => env('SUNSET_REDIS', 'default'),
    'workload_cache_ttl' => 5,
    'transports' => [
        'sqs' => [
            'sqs_max_delay' => 900,
            'long_delay_sweep_interval' => 60,
            'visibility_heartbeat' => false,
            'fifo' => [
                'message_group_id' => 'queue-name', // 'queue-name' | 'job-class' | callable
                'content_based_dedup' => true,
            ],
            'extended_payload' => [
                'enabled' => false,
                'bucket' => env('SUNSET_S3_BUCKET'),
                'prefix' => 'sunset-payloads/',
                'lifecycle_days' => 7,
            ],
        ],
    ],
];
```

`config/horizon.php` — set your supervisor connection to `sqs`.

## Operational notes

- **Long polling (default — saves money):** workers poll SQS with `WaitTimeSeconds=20` (the maximum) by default. With short-polling, every empty receive is a billable API call; long polling drastically reduces the request count on idle queues. To override, set `wait_time` (0–20) on your `sqs` queue connection in `config/queue.php` — for example `'wait_time' => 10` for faster idle-worker shutdown at the cost of more requests. `wait_time => 0` disables long polling (not recommended).
- **Visibility timeout:** set on each SQS queue to ≥ your job `timeout` × 1.5. (The `visibility_heartbeat` config knob is reserved for a later release — currently ignored.)
- **Extended payload cleanup:** add a lifecycle rule to your S3 bucket prefix (default `sunset-payloads/`) to clean up orphans from worker crashes.
- **Long-delay sweep:** auto-registered via Laravel's scheduler. Ensure `schedule:run` is wired in your cron.

## Migrating from `masonworkforce/horizon-sqs` v0.1.x

See [`UPGRADING.md`](UPGRADING.md).

## Testing locally

```bash
docker compose up -d
vendor/bin/phpunit
```

## License

MIT.
