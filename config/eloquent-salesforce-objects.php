<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Page Size
    |--------------------------------------------------------------------------
    |
    | The default number of records to retrieve per page when using pagination.
    | Salesforce has a maximum of 2000 records per query.
    |
    */
    'default_page_size' => env('SALESFORCE_PAGE_SIZE', 200),

    /*
    |--------------------------------------------------------------------------
    | Enable Query Log
    |--------------------------------------------------------------------------
    |
    | When enabled, all SOQL queries will be logged for debugging purposes.
    |
    */
    'enable_query_log' => env('SALESFORCE_QUERY_LOG', false),

    /*
    |--------------------------------------------------------------------------
    | Exception Handling & Logging
    |--------------------------------------------------------------------------
    |
    | Configure how Salesforce API exceptions are handled:
    |
    | 'throw_exceptions' - When true, exceptions are thrown (useful for development/debugging).
    |                      When false, exceptions are caught and logged, allowing graceful
    |                      degradation for production environments (timeouts, connection drops, etc.)
    |
    | 'logging_channel' - Laravel logging channel to use (null = default channel, false = disable logging)
    | 'log_level' - Default log level for Salesforce errors (emergency, alert, critical, error, warning, notice, info, debug)
    |
    */
    'throw_exceptions' => env('SALESFORCE_THROW_EXCEPTIONS', env('APP_DEBUG', false)),
    'logging_channel'  => env('SALESFORCE_LOG_CHANNEL', null),
    'log_level'        => env('SALESFORCE_LOG_LEVEL', 'error'),

    /*
    |--------------------------------------------------------------------------
    | Authentication Resilience
    |--------------------------------------------------------------------------
    |
    | Controls how the package handles Salesforce OAuth authentication under
    | concurrency and transient failures.
    |
    | 'retry_attempts'      - Total attempts (including the first) when calling
    |                         the OAuth endpoint. Only retries on transient signals:
    |                         connection errors, HTTP 5xx, and Salesforce's
    |                         documented 400 "unknown_error / retry your request".
    |                         Permanent errors (invalid_grant, bad credentials)
    |                         are never retried.
    | 'retry_base_delay_ms' - Base delay between attempts in milliseconds.
    |                         Backoff is exponential with jitter.
    | 'lock_wait_seconds'   - How long a worker will wait to acquire the
    |                         single-flight authentication lock.
    | 'lock_ttl_seconds'    - Maximum time the lock is held. Should comfortably
    |                         exceed worst-case OAuth round-trip + retries.
    |
    */
    'authentication' => [
        'retry_attempts'      => env('SALESFORCE_AUTH_RETRY_ATTEMPTS', 3),
        'retry_base_delay_ms' => env('SALESFORCE_AUTH_RETRY_BASE_DELAY_MS', 250),
        'lock_wait_seconds'   => env('SALESFORCE_AUTH_LOCK_WAIT_SECONDS', 8),
        'lock_ttl_seconds'    => env('SALESFORCE_AUTH_LOCK_TTL_SECONDS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Metadata Cache TTL
    |--------------------------------------------------------------------------
    |
    | The time-to-live (in seconds) for cached Salesforce object metadata
    | (describe results). Metadata changes infrequently, so this can be longer.
    |
    */
    'metadata_cache_ttl' => env('SALESFORCE_METADATA_CACHE_TTL', 86400), // 24 hours

    /*
    |--------------------------------------------------------------------------
    | No Soft Deletes Objects
    |--------------------------------------------------------------------------
    |
    | Salesforce objects that do not support the IsDeleted field.
    | Most objects support soft deletes, but some system objects like User do not.
    |
    */
    'no_soft_deletes' => ['User'],

    /*
    |--------------------------------------------------------------------------
    | Batch Query Size
    |--------------------------------------------------------------------------
    |
    | The number of queries to batch together when using SalesforceBatch.
    | Salesforce limits batch requests to a maximum of 25 queries per batch.
    |
    */
    'batch_size' => env('SALESFORCE_BATCH_SIZE', 25),

    /*
    |--------------------------------------------------------------------------
    | Bulk Operation Size
    |--------------------------------------------------------------------------
    |
    | The number of records to process in a single bulk operation (insert/update/delete).
    | Salesforce Composite SObject Collections API limits to 200 records per request.
    | Reducing this may help with memory or timeout issues.
    |
    */
    'bulk_operation_size' => env('SALESFORCE_BULK_OPERATION_SIZE', 200),

    /*
    |--------------------------------------------------------------------------
    | Model Generation
    |--------------------------------------------------------------------------
    |
    | Configuration for the make:salesforce-model artisan command.
    |
    | 'path' - Default directory for generated Salesforce models
    | 'namespace' - Default namespace for generated models
    | 'cast_map' - Maps Salesforce field types to Laravel cast types
    |
    */
    'model_generation' => [
        'path'      => app_path('Models/Salesforce'),
        'namespace' => 'App\\Models\\Salesforce',
        'cast_map'  => [
            'datetime' => 'datetime',
            'date'     => 'date',
            'boolean'  => 'boolean',
            'double'   => 'float',
            'currency' => 'float',
            'percent'  => 'float',
            'int'      => 'integer',
        ],
    ],

];
