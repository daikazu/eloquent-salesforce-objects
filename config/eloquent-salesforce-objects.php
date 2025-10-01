<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Query Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Control query result caching for improved performance. Caching dramatically
    | reduces API calls to Salesforce for repeated queries.
    |
    */
    'query_cache' => [
        // Enable/disable query result caching
        'enabled' => env('SALESFORCE_QUERY_CACHE', false),

        // Default TTL in seconds for cached queries (1 hour)
        'default_ttl' => env('SALESFORCE_QUERY_CACHE_TTL', 3600),

        // Cache driver (null uses Laravel's default cache driver)
        // Options: 'redis', 'database', 'file', 'memcached', 'array'
        'driver' => env('SALESFORCE_CACHE_DRIVER', null),

        // Per-object TTL overrides (in seconds)
        // Objects with frequent changes should have shorter TTLs
        'ttl_overrides' => [
            // 'Account' => 7200,      // 2 hours - less frequently changed
            // 'Opportunity' => 1800,   // 30 minutes - more frequently changed
            // 'Case' => 300,          // 5 minutes - very frequently changed
        ],

        // Automatically invalidate cache when local app makes changes
        // Listens to model created/updated/deleted events
        'auto_invalidate_on_local_changes' => env('SALESFORCE_AUTO_INVALIDATE_CACHE', true),

        // Cache invalidation strategy
        // 'record' - Surgical invalidation: only invalidates queries containing specific changed records (recommended)
        // 'object' - Broad invalidation: invalidates all queries for the entire object type
        'invalidation_strategy' => env('SALESFORCE_CACHE_INVALIDATION_STRATEGY', 'record'),

        // Webhook-based cache invalidation (requires Salesforce setup)
        // Enable when using Change Data Capture or Outbound Messages
        'webhook_invalidation' => env('SALESFORCE_WEBHOOK_INVALIDATION', false),

        // Webhook security secret for signature validation
        'webhook_secret' => env('SALESFORCE_WEBHOOK_SECRET'),

        // Require webhook validation (set to false for testing)
        'webhook_require_validation' => env('SALESFORCE_WEBHOOK_REQUIRE_VALIDATION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy Cache TTL (Deprecated)
    |--------------------------------------------------------------------------
    |
    | This setting is deprecated. Use 'query_cache.default_ttl' instead.
    | Kept for backward compatibility.
    |
    */
    'cache_ttl' => env('SALESFORCE_CACHE_TTL', 3600),

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
    | Enable Field Mapping
    |--------------------------------------------------------------------------
    |
    | When enabled, automatically converts Salesforce field names to Laravel
    | naming conventions in responses, and vice versa for create/update operations.
    |
    | Example: FirstName → first_name, Custom_Field__c → custom_field
    |
    | When disabled, field names are returned exactly as Salesforce provides them.
    |
    */
    'enable_field_mapping' => env('SALESFORCE_ENABLE_FIELD_MAPPING', false),

    /*
    |--------------------------------------------------------------------------
    | Field Naming Convention
    |--------------------------------------------------------------------------
    |
    | The naming convention for automatic field mapping between Laravel
    | attributes and Salesforce fields. Options: snake_case, camelCase, PascalCase
    |
    | Only applies when enable_field_mapping is true.
    |
    */
    'field_naming_convention' => env('SALESFORCE_NAMING_CONVENTION', 'snake_case'),

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
    | Custom Field Mappings
    |--------------------------------------------------------------------------
    |
    | Define custom mappings between Laravel attribute names and Salesforce
    | field names for cases where automatic mapping doesn't work.
    |
    */
    'field_mappings' => [
        // 'laravel_attribute' => 'Salesforce_Field__c',
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
    | The number of queries to batch together when using SOQLBatch.
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

];
