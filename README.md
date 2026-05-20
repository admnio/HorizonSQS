# Sunset for Laravel

Supercharged Laravel Horizon replacement. v0.6.0 ships the RabbitMQ transport alongside SQS (v0.2.0) and Redis (v0.3.0).

## Why

Sunset is the foundation for a multi-transport Horizon replacement: SQS, Redis, and RabbitMQ today (BullMQ and LavinMQ planned) all behind one consistent dashboard with deeper visibility into workers and queues than Horizon offers. v0.6.0 adds RabbitMQ as a third first-class transport. v0.5.0 owns the supervisor process tree — `sunset:work` replaces `php artisan horizon`. v0.4.0 owns the job lifecycle subsystem. The only remaining Horizon dependency is the dashboard (replaced in v1.0.0).

This release ships:

- Full Laravel Horizon support for Amazon SQS — same dashboard, same metrics, SQS underneath.
- Full Laravel Horizon support for Redis queues too — same dashboard, same metrics, Sunset-managed event dispatch
- Full Laravel Horizon support for RabbitMQ queues — same dashboard, same metrics, Sunset-managed event dispatch and delayed-job routing
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

## Not yet in v0.6.0 (planned)

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

## RabbitMQ

Sunset supports RabbitMQ as a peer transport to SQS and Redis. Jobs go through the same Sunset event lifecycle, show the same depth/throughput/payload data on the dashboard, and delayed dispatch routes through Sunset's `DelayedJobStore` (the same store SQS uses since v0.2.0) — so you get arbitrary-length delays even though RabbitMQ has no native delayed-message primitive.

### Installation

```bash
composer require admnio/sunset
```

The `vladimir-yuldashev/laravel-queue-rabbitmq` driver ships transitively — no separate require needed.

### `config/queue.php`

Add a `rabbitmq` connection block:

```php
'connections' => [
    // ... existing connections ...
    'rabbitmq' => [
        'driver' => 'rabbitmq',
        'queue' => 'default',
        'connection' => 'default',
        'hosts' => [[
            'host' => env('RABBITMQ_HOST', '127.0.0.1'),
            'port' => (int) env('RABBITMQ_PORT', 5672),
            'user' => env('RABBITMQ_USER', 'guest'),
            'password' => env('RABBITMQ_PASSWORD', 'guest'),
            'vhost' => env('RABBITMQ_VHOST', '/'),
        ]],
        'options' => [
            'queue' => [
                'exchange' => env('RABBITMQ_EXCHANGE', ''),
                'exchange_type' => 'direct',
            ],
        ],
    ],
],
```

Env-var fallbacks: `RABBITMQ_HOST`, `RABBITMQ_PORT`, `RABBITMQ_USER`, `RABBITMQ_PASSWORD`, `RABBITMQ_VHOST`.

### `config/sunset.php`

The published config exposes a `transports.rabbitmq` block:

```php
'transports' => [
    // ... sqs, redis ...
    'rabbitmq' => [
        // Connection name (from config/queue.php) used by RabbitTransport
        // for dashboard workload (depth) queries.
        'workload_connection' => env('SUNSET_RABBITMQ_WORKLOAD_CONN', 'rabbitmq'),

        // Opt-in dead-letter exchange. Scaffolded in v0.6.0; full
        // nack-on-drop routing lands in v0.7.0.
        'dead_letter' => [
            'enabled' => env('SUNSET_RABBITMQ_DLX_ENABLED', false),
            'exchange' => env('SUNSET_RABBITMQ_DLX_EXCHANGE', null),
        ],
    ],
],
```

### Production operations — queue bindings

**IMPORTANT:** RabbitMQ does NOT auto-bind queues to non-default exchanges. If you configure `options.queue.exchange` to a named exchange (e.g. `'amq.direct'`), you must declare the queue-to-exchange binding out-of-band (CLI, terraform, infrastructure-as-code). Otherwise messages published to that exchange will be silently dropped (no error, no retry — RabbitMQ's "no route" default).

For each queue you intend to publish to, declare a binding once per environment:

```bash
rabbitmqadmin declare binding source=amq.direct destination=<queue-name> routing_key=<queue-name>
```

Or use the equivalent HTTP management API call.

If you set `options.queue.exchange = ''` (the empty / default exchange), no binding setup is required — RabbitMQ routes by queue name automatically. **For simple deployments, prefer the default exchange.**

### Delayed jobs

```php
Queue::connection('rabbitmq')->later(60, $job);
```

Delays route through Sunset's `DelayedJobStore` (Redis-backed ZSET). The auto-scheduled `sunset:sweep-delayed` command — the same one v0.2.0 added for SQS — pops due jobs back into RabbitMQ. Make sure your Laravel scheduler (`schedule:run`) is wired in cron; no consumer-side changes from the SQS setup.

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
