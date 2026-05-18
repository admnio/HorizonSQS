<?php

return [
    'redis_connection' => env('SUNSET_REDIS', 'default'),

    'workload_cache_ttl' => 5,

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
    ],
];
