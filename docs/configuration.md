# Configuration Reference

Complete guide to configuring the Eloquent Salesforce Objects package.

## Table of Contents

- [Configuration File](#configuration-file)
- [Environment Variables](#environment-variables)
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

## Complete Configuration Example

Here's a complete, recommended production configuration:

```php
<?php

return [
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
SALESFORCE_THROW_EXCEPTIONS=true        # See errors immediately
SALESFORCE_QUERY_LOG=true               # Debug queries
SALESFORCE_LOG_LEVEL=debug              # Verbose logging
```

### Staging Environment

```env
# .env.staging
SALESFORCE_THROW_EXCEPTIONS=true
SALESFORCE_QUERY_LOG=false
SALESFORCE_LOG_LEVEL=warning
```

### Production Environment

```env
# .env.production
SALESFORCE_THROW_EXCEPTIONS=false       # Graceful degradation
SALESFORCE_QUERY_LOG=false              # No verbose logging
SALESFORCE_LOG_LEVEL=error              # Only errors
```

## Configuration Best Practices

### 1. Disable Exceptions in Production

```env
SALESFORCE_THROW_EXCEPTIONS=false
```

Prevents user-facing errors, logs issues instead.

### 2. Environment-Specific Settings

Use different `.env` files for each environment with appropriate settings.

## Verifying Configuration

Check your configuration:

```bash
php artisan tinker
```

```php
// Check error handling
config('eloquent-salesforce-objects.throw_exceptions')

// Check metadata cache TTL
config('eloquent-salesforce-objects.metadata_cache_ttl')

// Check field mapping
config('eloquent-salesforce-objects.enable_field_mapping')
```

## Next Steps

- **[Installation](installation.md)** - Initial setup guide
- **[Troubleshooting](troubleshooting.md)** - Common issues and solutions
