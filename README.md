# Sunset for Laravel

Supercharged Laravel Horizon replacement. v0.2.0 ships the SQS transport.

## Why

Sunset is the foundation for a multi-transport Horizon replacement: SQS and Redis today (BullMQ, RabbitMQ, and LavinMQ planned) all behind one consistent dashboard with deeper visibility into workers and queues than Horizon offers. v0.5.0 owns the supervisor process tree — `sunset:work` replaces `php artisan horizon`. v0.4.0 owns the job lifecycle subsystem. The only remaining Horizon dependency is the dashboard (replaced in v1.0.0).

This release ships:

- Full Laravel Horizon support for Amazon SQS — same dashboard, same metrics, SQS underneath.
- Full Laravel Horizon support for Redis queues too — same dashboard, same metrics, Sunset-managed event dispatch
- Sunset-owned job lifecycle: events, listeners, repositories, and `JobPayload` live under `Admnio\Sunset\*`. Transports dispatch Sunset events; Sunset listeners record to `sunset:*` Redis keys
- `sunset:migrate-horizon-keys` artisan command for renaming legacy `horizon:*` keys to `sunset:*` (idempotent, supports `--dry-run`)
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
- Sunset-owned supervisor process tree: `sunset:work` replaces `php artisan horizon`. Master/Supervisor/Process repositories live under `Admnio\Sunset`. `php artisan horizon` is replaced with a removal notice that exits non-zero.
- 19 new `sunset:*` artisan commands covering the full Horizon CLI surface: process tree (`work`/`supervise`/`worker`), control (`pause`/`continue`/`pause-supervisor`/`continue-supervisor`/`terminate`/`status`/`supervisors`/`supervisor-status`), maintenance (`clear`/`purge`/`snapshot`/`forget-failed`), operator (`install`/`publish`), migration (`migrate-horizon-config`).
- `sunset:migrate-horizon-config` artisan command for moving `config/horizon.php`'s `environments` block to `config/sunset.php`'s `supervisors`.

## Not yet in v0.5.0 (planned)

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
    'key_prefix' => env('SUNSET_KEY_PREFIX', 'sunset'),
    'memory_limit' => 64,          // MB; supervisor restarts workers above this
    'fast_termination' => false,   // true = SIGKILL immediately on terminate signal
    'wait' => 60,                  // seconds to wait for graceful drain before force-kill
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],
    'supervisors' => [
        'production' => [
            'my-supervisor' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'auto',
                'processes' => 10,
                'tries' => 3,
                'timeout' => 60,
            ],
        ],
    ],
    'transports' => [
        // ... unchanged ...
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
