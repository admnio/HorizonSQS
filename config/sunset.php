<?php

return [
    'redis_connection' => env('SUNSET_REDIS', 'default'),

    'workload_cache_ttl' => 5,

    // Redis key prefix for everything Sunset records (jobs, tags, metrics,
    // failed-job index, etc.). Override only if 'sunset' collides with
    // another namespace in your Redis instance.
    'key_prefix' => env('SUNSET_KEY_PREFIX', 'sunset'),

    // Trim windows (minutes) for the Sunset job index zsets. Match
    // Horizon's defaults so existing Horizon-installed cron timing keeps
    // working. Override per-deploy if needed.
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'transports' => [
        'sqs' => [
            'sqs_max_delay' => 900,

            'long_delay_sweep_interval' => 60,

            // Reserved for a later release of the Sunset roadmap. Flag accepted by config but not yet wired.
            'visibility_heartbeat' => false,

            'fifo' => [
                // 'queue-name' | 'job-class' | callable(array $payload, string $queue): string
                'message_group_id' => 'queue-name',
                'content_based_dedup' => true,
            ],

            'extended_payload' => [
                'enabled' => false,
                'bucket' => env('SUNSET_S3_BUCKET'),
                'prefix' => 'sunset-payloads/',
                'lifecycle_days' => 7,
            ],
        ],

        'redis' => [
            // Redis connection name (from config/database.php) used by Sunset's
            // workload queries. Typically the same connection your queues live on.
            'workload_connection' => env('SUNSET_REDIS_WORKLOAD_CONN', 'default'),
        ],

        'rabbitmq' => [
            // Connection name (from config/queue.php) used by RabbitTransport
            // for workload queries.
            'workload_connection' => env('SUNSET_RABBITMQ_WORKLOAD_CONN', 'rabbitmq'),

            // Opt-in dead-letter exchange. When enabled, jobs nacked without
            // requeue land in a DLX queue. The DLX assertion runs on first
            // RabbitTransport::connect(). The 'exchange' value defaults to
            // "<vhost>.dlx" when null.
            'dead_letter' => [
                'enabled' => env('SUNSET_RABBITMQ_DLX_ENABLED', false),
                'exchange' => env('SUNSET_RABBITMQ_DLX_EXCHANGE', null),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits (v0.7.0)
    |--------------------------------------------------------------------------
    |
    | Configure global behaviors for rate-limit declarations made via the
    | Sunset facade in service providers.
    */

    'rate_limits' => [
        // When true, every pop attempt (admit OR reject) consumes a throttle
        // token. Default false: rejected jobs don't burn tokens.
        'count_releases_by_default' => env('SUNSET_RATE_LIMITS_COUNT_RELEASES', false),

        // When true, Redis-unavailable during a limit check releases the job
        // with a 30s fixed backoff. When false (default), the gate fails open
        // — admit the job and log a warning. Pick false when uptime > quota
        // protection; true when quota > uptime.
        'fail_closed' => env('SUNSET_RATE_LIMITS_FAIL_CLOSED', false),

        // Cadence (seconds) of the safety-net Lua reconciliation that cleans
        // up leaked concurrency slots. Used by sunset:sweep-rate-limit-slots.
        'sweep_interval_seconds' => (int) env('SUNSET_RATE_LIMITS_SWEEP_INTERVAL', 60),
    ],
];
