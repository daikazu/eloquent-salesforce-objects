# Configuration Reference

Complete guide to configuring the Eloquent Salesforce Objects package.

## Table of Contents

- [Configuration File](#configuration-file)
- [Environment Variables](#environment-variables)
- [Query Caching](#query-caching)
- [Field Mapping](#field-mapping)
- [Error Handling](#error-handling)
- [Performance Options](#performance-options)
- [Advanced Configuration](#advanced-configuration)

## Configuration File

The main configuration file is located at `config/eloquent-salesforce-objects.php`.

Publish the configuration:

```bash
php artisan vendor:publish --tag="eloquent-salesforce-objects-config"
```

## Environment Variables

### Required Configuration

```env
# Salesforce OAuth Credentials
CONSUMER_KEY=your_consumer_key
CONSUMER_SECRET=your_consumer_secret
CALLBACK_URI=https://your-domain.com/callback
LOGIN_URL=https://login.salesforce.com

# Salesforce Authentication
USERNAME=your-salesforce-username
PASSWORD=your-salesforce-password

# Salesforce Instance
SALESFORCE_INSTANCE_URL=https://your-instance.salesforce.com
SALESFORCE_API_VERSION=v64.0
```

### Optional Configuration

```env
# Query Caching
SALESFORCE_QUERY_CACHE=true
SALESFORCE_QUERY_CACHE_TTL=3600
SALESFORCE_CACHE_DRIVER=redis
SALESFORCE_CACHE_INVALIDATION_STRATEGY=record
SALESFORCE_AUTO_INVALIDATE_CACHE=true

# Webhook Integration
SALESFORCE_WEBHOOK_INVALIDATION=false
SALESFORCE_WEBHOOK_SECRET=your-webhook-secret
SALESFORCE_WEBHOOK_REQUIRE_VALIDATION=true

# Performance
SALESFORCE_PAGE_SIZE=200
SALESFORCE_BULK_OPERATION_SIZE=200
SALESFORCE_BATCH_SIZE=25

# Field Mapping
SALESFORCE_ENABLE_FIELD_MAPPING=false
SALESFORCE_NAMING_CONVENTION=snake_case

# Debugging
SALESFORCE_QUERY_LOG=false
SALESFORCE_THROW_EXCEPTIONS=true
SALESFORCE_LOG_LEVEL=error
```

## Query Caching

Configure query caching for improved performance.

### Enable/Disable Caching

```php
// config/eloquent-salesforce-objects.php
'query_cache' => [
    'enabled' => env('SALESFORCE_QUERY_CACHE', true),
],
```

```env
# .env
SALESFORCE_QUERY_CACHE=true
```

### Cache TTL (Time-To-Live)

```php
'query_cache' => [
    'default_ttl' => env('SALESFORCE_QUERY_CACHE_TTL', 3600), // 1 hour
],
```

Override per object:

```php
'query_cache' => [
    'ttl_overrides' => [
        'Account' => 7200,      // 2 hours - stable data
        'Opportunity' => 1800,   // 30 minutes - frequently changed
        'Case' => 300,          // 5 minutes - real-time data
    ],
],
```

### Cache Driver

```php
'query_cache' => [
    'driver' => env('SALESFORCE_CACHE_DRIVER', null), // null = default Laravel cache
],
```

```env
# .env - Recommended drivers
SALESFORCE_CACHE_DRIVER=redis     # Best performance
SALESFORCE_CACHE_DRIVER=memcached # Also good
SALESFORCE_CACHE_DRIVER=file      # OK for development
SALESFORCE_CACHE_DRIVER=database  # OK but slower
```

**Note:** Redis or Memcached recommended for production.

### Invalidation Strategy

```php
'query_cache' => [
    // 'record' - Surgical invalidation (only affected queries)
    // 'object' - Broad invalidation (all queries for object)
    'invalidation_strategy' => env('SALESFORCE_CACHE_INVALIDATION_STRATEGY', 'record'),
],
```

```env
SALESFORCE_CACHE_INVALIDATION_STRATEGY=record  # Recommended
```

### Auto-Invalidation

```php
'query_cache' => [
    'auto_invalidate_on_local_changes' => env('SALESFORCE_AUTO_INVALIDATE_CACHE', true),
],
```

When `true`, cache automatically invalidates when your Laravel app modifies records.

**Note:** Aggregate queries (COUNT, SUM, AVG, MIN, MAX) are never cached to ensure you always get current, accurate data.

### Webhook-Based Invalidation

```php
'query_cache' => [
    'webhook_invalidation' => env('SALESFORCE_WEBHOOK_INVALIDATION', false),
    'webhook_secret' => env('SALESFORCE_WEBHOOK_SECRET'),
    'webhook_require_validation' => env('SALESFORCE_WEBHOOK_REQUIRE_VALIDATION', true),
],
```

Enable for real-time cache invalidation from external Salesforce changes. See [Webhook Setup](webhooks.md).

## Field Mapping

Automatically convert between Laravel and Salesforce naming conventions.

### Enable Field Mapping

```php
'enable_field_mapping' => env('SALESFORCE_ENABLE_FIELD_MAPPING', false),
```

**Default: false** (disabled to avoid confusion)

### Naming Convention

```php
'field_naming_convention' => env('SALESFORCE_NAMING_CONVENTION', 'snake_case'),
```

Options:
- `snake_case`: `first_name`, `custom_field`
- `camelCase`: `firstName`, `customField`
- `PascalCase`: `FirstName`, `CustomField` (Salesforce default)

### Custom Field Mappings

```php
'field_mappings' => [
    'annual_revenue' => 'AnnualRevenue',
    'account_name' => 'Name',
    'custom_id' => 'Custom_Id__c',
],
```

Define specific field name mappings.

## Error Handling

Configure how the package handles errors.

### Throw Exceptions

```php
'throw_exceptions' => env('SALESFORCE_THROW_EXCEPTIONS', env('APP_DEBUG', false)),
```

- `true`: Throw exceptions (good for development, you see errors immediately)
- `false`: Log errors and return false (good for production, graceful degradation)

### Logging Configuration

```php
'logging_channel' => env('SALESFORCE_LOG_CHANNEL', null),
'log_level' => env('SALESFORCE_LOG_LEVEL', 'error'),
```

```env
# .env
SALESFORCE_LOG_CHANNEL=salesforce  # Custom channel
SALESFORCE_LOG_LEVEL=error         # error, warning, info, debug
```

Log levels:
- `emergency`: System unusable
- `alert`: Action required immediately
- `critical`: Critical conditions
- `error`: Runtime errors (default)
- `warning`: Warning messages
- `notice`: Normal but significant
- `info`: Informational messages
- `debug`: Debug-level messages

## Performance Options

### Default Page Size

```php
'default_page_size' => env('SALESFORCE_PAGE_SIZE', 200),
```

Number of records per page when using pagination. Max: 2000.

### Bulk Operation Size

```php
'bulk_operation_size' => env('SALESFORCE_BULK_OPERATION_SIZE', 200),
```

Records per bulk insert/update/delete operation. Max: 200 (Salesforce limit).

### Batch Query Size

```php
'batch_size' => env('SALESFORCE_BATCH_SIZE', 25),
```

Number of queries to batch together. Max: 25 (Salesforce limit).

### Metadata Cache TTL

```php
'metadata_cache_ttl' => env('SALESFORCE_METADATA_CACHE_TTL', 86400), // 24 hours
```

How long to cache Salesforce object metadata (describe results).

## Advanced Configuration

### Soft Deletes Configuration

```php
'no_soft_deletes' => ['User', 'Profile'],
```

Salesforce objects that don't support the `IsDeleted` field.

### Query Logging

```php
'enable_query_log' => env('SALESFORCE_QUERY_LOG', false),
```

Enable to log all SOQL queries. Useful for debugging but verbose.

### Legacy Cache TTL

```php
'cache_ttl' => env('SALESFORCE_CACHE_TTL', 3600),
```

**Deprecated:** Use `query_cache.default_ttl` instead.

## Complete Configuration Example

Here's a complete, recommended production configuration:

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Query Cache Configuration
    |--------------------------------------------------------------------------
    */
    'query_cache' => [
        'enabled' => env('SALESFORCE_QUERY_CACHE', true),
        'default_ttl' => env('SALESFORCE_QUERY_CACHE_TTL', 3600),
        'driver' => env('SALESFORCE_CACHE_DRIVER', 'redis'),

        'ttl_overrides' => [
            'Account' => 7200,      // 2 hours
            'Contact' => 3600,       // 1 hour
            'Opportunity' => 1800,   // 30 minutes
            'Case' => 600,          // 10 minutes
            'Lead' => 1800,         // 30 minutes
        ],

        'auto_invalidate_on_local_changes' => true,
        'invalidation_strategy' => 'record',

        // Webhook configuration
        'webhook_invalidation' => env('SALESFORCE_WEBHOOK_INVALIDATION', false),
        'webhook_secret' => env('SALESFORCE_WEBHOOK_SECRET'),
        'webhook_require_validation' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    */
    'default_page_size' => env('SALESFORCE_PAGE_SIZE', 200),
    'bulk_operation_size' => env('SALESFORCE_BULK_OPERATION_SIZE', 200),
    'batch_size' => env('SALESFORCE_BATCH_SIZE', 25),
    'metadata_cache_ttl' => env('SALESFORCE_METADATA_CACHE_TTL', 86400),

    /*
    |--------------------------------------------------------------------------
    | Field Mapping Configuration
    |--------------------------------------------------------------------------
    */
    'enable_field_mapping' => env('SALESFORCE_ENABLE_FIELD_MAPPING', false),
    'field_naming_convention' => env('SALESFORCE_NAMING_CONVENTION', 'snake_case'),
    'field_mappings' => [],

    /*
    |--------------------------------------------------------------------------
    | Error Handling & Logging
    |--------------------------------------------------------------------------
    */
    'throw_exceptions' => env('SALESFORCE_THROW_EXCEPTIONS', false),
    'logging_channel' => env('SALESFORCE_LOG_CHANNEL', null),
    'log_level' => env('SALESFORCE_LOG_LEVEL', 'error'),
    'enable_query_log' => env('SALESFORCE_QUERY_LOG', false),

    /*
    |--------------------------------------------------------------------------
    | Advanced Configuration
    |--------------------------------------------------------------------------
    */
    'no_soft_deletes' => ['User', 'Profile'],
];
```

## Environment-Specific Configuration

### Development Environment

```env
# .env.local
SALESFORCE_QUERY_CACHE=true
SALESFORCE_QUERY_CACHE_TTL=300          # 5 minutes (short for development)
SALESFORCE_CACHE_DRIVER=file            # Simple for development
SALESFORCE_THROW_EXCEPTIONS=true        # See errors immediately
SALESFORCE_QUERY_LOG=true               # Debug queries
SALESFORCE_LOG_LEVEL=debug              # Verbose logging
```

### Staging Environment

```env
# .env.staging
SALESFORCE_QUERY_CACHE=true
SALESFORCE_QUERY_CACHE_TTL=1800         # 30 minutes
SALESFORCE_CACHE_DRIVER=redis
SALESFORCE_THROW_EXCEPTIONS=true
SALESFORCE_QUERY_LOG=false
SALESFORCE_LOG_LEVEL=warning
SALESFORCE_WEBHOOK_INVALIDATION=true    # Test webhooks
```

### Production Environment

```env
# .env.production
SALESFORCE_QUERY_CACHE=true
SALESFORCE_QUERY_CACHE_TTL=3600         # 1 hour
SALESFORCE_CACHE_DRIVER=redis           # Fast, reliable
SALESFORCE_THROW_EXCEPTIONS=false       # Graceful degradation
SALESFORCE_QUERY_LOG=false              # No verbose logging
SALESFORCE_LOG_LEVEL=error              # Only errors
SALESFORCE_WEBHOOK_INVALIDATION=true    # Real-time invalidation
SALESFORCE_CACHE_INVALIDATION_STRATEGY=record  # Efficient invalidation
```

## Configuration Best Practices

### 1. Use Redis for Caching

```env
SALESFORCE_CACHE_DRIVER=redis
```

Redis provides the best performance and supports cache tagging.

### 2. Configure Appropriate TTLs

```php
'ttl_overrides' => [
    'Account' => 7200,      // Stable data - longer TTL
    'Case' => 300,          // Real-time data - shorter TTL
],
```

Match TTL to data volatility.

### 3. Enable Webhooks in Production

```env
SALESFORCE_WEBHOOK_INVALIDATION=true
```

Keeps cache fresh even with external changes.

### 4. Use Record-Level Invalidation

```env
SALESFORCE_CACHE_INVALIDATION_STRATEGY=record
```

More efficient for multi-user applications.

### 5. Disable Exceptions in Production

```env
SALESFORCE_THROW_EXCEPTIONS=false
```

Prevents user-facing errors, logs issues instead.

### 6. Environment-Specific Settings

Use different `.env` files for each environment with appropriate settings.

## Verifying Configuration

Check your configuration:

```bash
php artisan tinker
```

```php
// Check if caching is enabled
config('eloquent-salesforce-objects.query_cache.enabled')

// Check cache driver
config('eloquent-salesforce-objects.query_cache.driver')

// Check invalidation strategy
config('eloquent-salesforce-objects.query_cache.invalidation_strategy')

// Check webhook settings
config('eloquent-salesforce-objects.query_cache.webhook_invalidation')
```

## Next Steps

- **[Installation](installation.md)** - Initial setup guide
- **[Caching](caching.md)** - Detailed caching documentation
- **[Webhooks](webhooks.md)** - Webhook setup guide
- **[Troubleshooting](troubleshooting.md)** - Common issues and solutions
