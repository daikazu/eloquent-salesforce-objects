# Configuration

This page covers the configuration for **this package only**. For Salesforce connection setup (credentials, OAuth, API version), see the [omniphx/forrest documentation](https://github.com/omniphx/forrest).

## Publish Config

```bash
php artisan vendor:publish --tag="eloquent-salesforce-objects-config"
```

Creates `config/eloquent-salesforce-objects.php`.

## Options

### Performance

| Key | Default | Description |
|-----|---------|-------------|
| `default_page_size` | `200` | Records per page when paginating (max 2000) |
| `bulk_operation_size` | `200` | Records per bulk insert/update/delete (max 200) |
| `batch_size` | `25` | Queries per batch request (max 25) |
| `metadata_cache_ttl` | `86400` | Seconds to cache describe results (default 24h) |

### Field Mapping

| Key | Default | Description |
|-----|---------|-------------|
| `enable_field_mapping` | `false` | Auto-convert between Laravel and Salesforce naming conventions |
| `field_naming_convention` | `snake_case` | Convention to use: `snake_case`, `camelCase`, or `PascalCase` |
| `field_mappings` | `[]` | Custom field name mappings (overrides automatic conversion) |

```php
// Example: custom field mappings
'field_mappings' => [
    'annual_revenue' => 'AnnualRevenue',
    'custom_id' => 'Custom_Id__c',
],
```

### Error Handling

| Key | Default | Description |
|-----|---------|-------------|
| `throw_exceptions` | `APP_DEBUG` | `true`: throw on API errors. `false`: log and return false |
| `logging_channel` | `null` | Laravel log channel to use (`null` = default channel) |
| `log_level` | `error` | Log level for Salesforce errors |
| `enable_query_log` | `false` | Log all SOQL queries (useful for debugging) |

### Other

| Key | Default | Description |
|-----|---------|-------------|
| `no_soft_deletes` | `['User']` | Objects that don't support the `IsDeleted` field |

### Model Generation

| Key | Default | Description |
|-----|---------|-------------|
| `model_generation.path` | `app_path('Models/Salesforce')` | Output directory for generated models |
| `model_generation.namespace` | `App\Models\Salesforce` | Namespace for generated models |
| `model_generation.cast_map` | *(see below)* | Salesforce field type to Laravel cast mapping |

```php
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
```

## Environment Variables

All package settings can be set via `.env`:

```env
# Performance
SALESFORCE_PAGE_SIZE=200
SALESFORCE_BULK_OPERATION_SIZE=200
SALESFORCE_BATCH_SIZE=25
SALESFORCE_METADATA_CACHE_TTL=86400

# Field Mapping
SALESFORCE_ENABLE_FIELD_MAPPING=false
SALESFORCE_NAMING_CONVENTION=snake_case

# Error Handling
SALESFORCE_THROW_EXCEPTIONS=true
SALESFORCE_LOG_CHANNEL=
SALESFORCE_LOG_LEVEL=error
SALESFORCE_QUERY_LOG=false
```

**Tip:** Use `SALESFORCE_THROW_EXCEPTIONS=true` in development and `false` in production for graceful degradation.
