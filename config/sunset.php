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
];
