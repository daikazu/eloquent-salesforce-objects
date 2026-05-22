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

### Error Handling

| Key | Default | Description |
|-----|---------|-------------|
| `throw_exceptions` | `APP_DEBUG` | `true`: throw on API errors. `false`: log and return false |
| `logging_channel` | `null` | Laravel log channel to use (`null` = default channel) |
| `log_level` | `error` | Log level for Salesforce errors |
| `enable_query_log` | `false` | Log all SOQL queries (useful for debugging) |

### Authentication Resilience

Controls how the package handles Salesforce OAuth authentication under concurrency and transient failures. Defaults are safe for most apps; tune these only if you see auth-related issues.

| Key | Default | Description |
|-----|---------|-------------|
| `authentication.retry_attempts` | `3` | Total OAuth attempts (incl. first). Only retries on transient errors. |
| `authentication.retry_base_delay_ms` | `250` | Base delay between retries (ms). Backoff is exponential + jitter. |
| `authentication.lock_wait_seconds` | `8` | How long a worker waits to acquire the single-flight auth lock. |
| `authentication.lock_ttl_seconds` | `10` | Max time the auth lock is held. |

**What gets retried:**

- Salesforce's `400 { "error": "unknown_error", "error_description": "retry your request" }` (Salesforce-side transient throttle on the OAuth endpoint).
- HTTP 5xx responses.
- Network/TLS errors (`GuzzleHttp\Exception\ConnectException`).

**What never gets retried:**

- `invalid_grant`, `invalid_client_id`, `authentication failure` — credential/config issues. Retrying makes the problem worse.
- All other 4xx responses.

**Why the lock exists:** without it, if many workers (e.g. Horizon queue workers) hit a cache miss simultaneously, they would all `POST /services/oauth2/token` in parallel. Salesforce throttles concurrent OAuth requests for the same connected app + user, which produces the `unknown_error / retry your request` failure described above. The lock collapses the herd: one worker authenticates, the rest read the freshly-cached token.

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

# Error Handling
SALESFORCE_THROW_EXCEPTIONS=true
SALESFORCE_LOG_CHANNEL=
SALESFORCE_LOG_LEVEL=error
SALESFORCE_QUERY_LOG=false

# Authentication Resilience
SALESFORCE_AUTH_RETRY_ATTEMPTS=3
SALESFORCE_AUTH_RETRY_BASE_DELAY_MS=250
SALESFORCE_AUTH_LOCK_WAIT_SECONDS=8
SALESFORCE_AUTH_LOCK_TTL_SECONDS=10
```

**Tip:** Use `SALESFORCE_THROW_EXCEPTIONS=true` in development and `false` in production for graceful degradation.
