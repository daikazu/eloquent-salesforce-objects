# Salesforce Query Caching

This package includes a powerful query caching system that dramatically reduces API calls to Salesforce by caching query results with smart invalidation strategies.

## Quick Start

### Enable Caching

Caching is **enabled by default**. To disable it:

```env
# .env
SALESFORCE_QUERY_CACHE=false
```

### Configuration

Publish and customize the config file:

```bash
php artisan vendor:publish --tag="eloquent-salesforce-objects-config"
```

```php
// config/eloquent-salesforce-objects.php
'query_cache' => [
    'enabled' => true,
    'default_ttl' => 3600, // 1 hour
    'driver' => null, // null = default Laravel cache

    // Per-object TTL overrides
    'ttl_overrides' => [
        'Account' => 7200,      // 2 hours
        'Opportunity' => 1800,  // 30 minutes
        'Case' => 300,          // 5 minutes
    ],

    // Auto-invalidate on local changes
    'auto_invalidate_on_local_changes' => true,
],
```

## Usage

### Basic Usage

Queries are automatically cached:

```php
// First call - hits Salesforce API and caches result
$accounts = Account::where('Industry', 'Technology')->get();

// Second call - returns cached result (no API call!)
$accounts = Account::where('Industry', 'Technology')->get();
```

### Query Control Methods

#### Skip Cache

Force query to bypass cache:

```php
Account::withoutCache()
    ->where('Industry', 'Tech')
    ->get();
```

#### Custom TTL

Set custom cache duration:

```php
// Cache for 10 minutes
Account::cacheFor(600)
    ->where('Industry', 'Tech')
    ->get();
```

#### Refresh Cache

Force cache refresh with fresh data:

```php
Account::refreshCache()
    ->where('Industry', 'Tech')
    ->get();
```

#### Custom Tags

Add custom cache tags for fine-grained invalidation:

```php
Account::cacheTags(['tech-accounts', 'q2-2024'])
    ->where('Industry', 'Tech')
    ->get();

// Later, invalidate by tag
Cache::tags(['tech-accounts'])->flush();
```

### Method Chaining

All cache methods are chainable:

```php
Account::withoutCache()
    ->where('Industry', 'Tech')
    ->orderBy('Name')
    ->limit(100)
    ->get();

Account::cacheFor(300)
    ->cacheTags(['hot-leads'])
    ->where('Status', 'Hot')
    ->get();
```

## Cache Invalidation

### Invalidation Strategies

This package supports two cache invalidation strategies:

#### Record-Level Invalidation (Default - Recommended)

**Surgical invalidation:** Only invalidates cached queries that contain the specific changed record.

```php
// config/eloquent-salesforce-objects.php
'query_cache' => [
    'invalidation_strategy' => 'record', // Default
],
```

**Example Scenario:**
```php
// User A caches this query (contains Opportunity #1)
$userAOpps = Opportunity::where('OwnerId', 'user-a-id')->get();

// User B caches this query (contains Opportunity #2)
$userBOpps = Opportunity::where('OwnerId', 'user-b-id')->get();

// User A updates their Opportunity #1
$opp1 = Opportunity::find('opportunity-1-id');
$opp1->Stage = 'Closed Won';
$opp1->save();

// Result: Only User A's cache is invalidated
// User B's cache remains intact!
```

**Benefits:**
- Dramatically better performance for high-frequency objects (Opportunities, Cases)
- Multiple users can work independently without thrashing each other's cache
- Reduced API calls in multi-user scenarios
- Ideal for production environments with many concurrent users

#### Object-Level Invalidation (Legacy)

**Broad invalidation:** Invalidates ALL cached queries for the entire object type.

```php
// config/eloquent-salesforce-objects.php
'query_cache' => [
    'invalidation_strategy' => 'object',
],
```

**Example:**
```php
// ANY change to ANY Account invalidates ALL Account queries
$account->save(); // Clears all Account cache
```

**Use Cases:**
- Simpler scenarios with low query volume
- Development environments
- Objects with very few cached queries
- Backward compatibility

### Automatic Invalidation

Cache is **automatically invalidated** when you create, update, or delete records through your Laravel application:

```php
// Create - invalidates cache for the object
// (Always uses object-level for new records since we don't know which queries match)
$account = Account::create([
    'Name' => 'Acme Corp',
    'Industry' => 'Technology',
]);

// Update - uses configured strategy
// record: Only invalidates queries containing this specific Account
// object: Invalidates ALL Account queries
$account->Industry = 'Finance';
$account->save();

// Delete - uses configured strategy
$account->delete();
```

**Configuration:**
```env
# .env
# Options: 'record' (recommended) or 'object'
SALESFORCE_CACHE_INVALIDATION_STRATEGY=record
```

### Manual Cache Clearing

#### Using Artisan Command

```bash
# Interactive mode
php artisan salesforce:cache-clear

# Clear all cache
php artisan salesforce:cache-clear --all

# Clear specific object
php artisan salesforce:cache-clear Account

# Show statistics
php artisan salesforce:cache-clear --stats
```

#### Programmatically

```php
use Daikazu\EloquentSalesforceObjects\Support\QueryCache;

$cache = app(QueryCache::class);

// Clear specific object
$cache->flushObject('Account');

// Clear multiple objects
$cache->flushObject(['Account', 'Contact']);

// Clear all Salesforce cache
$cache->flushAll();

// Invalidate specific record (flushes object cache)
$cache->invalidateRecord('Account', '001xx000003DGb2AAG');
```

## Cache Analytics

Track cache performance:

```php
use Daikazu\EloquentSalesforceObjects\Support\QueryCache;

$cache = app(QueryCache::class);
$stats = $cache->getStatistics();

// Returns:
// [
//     'enabled' => true,
//     'hits' => 1250,
//     'misses' => 100,
//     'total' => 1350,
//     'hit_rate_percentage' => 92.59,
// ]
```

Enable analytics by setting:

```php
'enable_query_log' => true,
```

## Aggregate Query Caching

**Aggregate queries (COUNT, SUM, AVG, MIN, MAX) are NEVER cached.** This is intentional because:

- Aggregate queries are typically used for real-time reports and dashboards where current data is critical
- Users expect accurate counts and totals that reflect the current state
- Aggregate queries are relatively fast since they don't return large datasets
- Cached aggregates can show stale data that's confusing (e.g., count shows 100 but a new record exists)

### Always Get Fresh Aggregate Data

```php
// These always hit Salesforce API (never cached)
$totalAccounts = Account::count();
$totalRevenue = Account::sum('AnnualRevenue');
$avgDealSize = Opportunity::avg('Amount');
```

### Best Practice for Expensive Aggregates

For expensive aggregate calculations that you want to cache, calculate and cache the result explicitly:

```php
// Cache the result yourself with explicit control

$totalRevenue = Cache::remember('dashboard_total_revenue', 300, function () {
    return Account::sum('AnnualRevenue');
});

// Refresh this cache on a schedule or when needed
Cache::forget('dashboard_total_revenue');
```

This gives you explicit control over when cached aggregate values are refreshed.

## Advanced Scenarios

### Disabling Auto-Invalidation

If you want manual control over cache invalidation:

```php
// config/eloquent-salesforce-objects.php
'query_cache' => [
    'auto_invalidate_on_local_changes' => false,
],
```

Now you must manually invalidate cache:

```php
$account->save();

// Manually invalidate
app(QueryCache::class)->flushObject('Account');
```

### Different Cache Drivers

Use Redis or Memcached for better performance:

```env
SALESFORCE_CACHE_DRIVER=redis
```

Ensure your cache driver supports tagging (Redis, Memcached). File and database drivers have limited tag support.

### Per-Environment Configuration

```env
# .env.local (development - short TTL, frequent invalidation)
SALESFORCE_QUERY_CACHE=true
SALESFORCE_QUERY_CACHE_TTL=300  # 5 minutes
SALESFORCE_AUTO_INVALIDATE_CACHE=true

# .env.production (production - longer TTL)
SALESFORCE_QUERY_CACHE=true
SALESFORCE_QUERY_CACHE_TTL=7200  # 2 hours
SALESFORCE_AUTO_INVALIDATE_CACHE=true
```

## Examples

### Caching Reports

```php
// Cache expensive report for 1 hour
$report = Account::cacheFor(3600)
    ->select('Industry', DB::raw('COUNT(*) as total'))
    ->groupBy('Industry')
    ->get();
```

### Real-time Data

```php
// Always get fresh data for critical operations
$opportunity = Opportunity::withoutCache()
    ->find($id);
```

### Bulk Operations

```php
// Refresh cache after bulk import
Account::insert($bulkData);

// Manually refresh commonly accessed query
Account::refreshCache()
    ->where('Status', 'Active')
    ->get();
```

### Scheduled Cache Warming

Warm cache for common queries:

```php
// app/Console/Kernel.php
$schedule->call(function () {
    // Warm cache for dashboard queries
    Account::refreshCache()
        ->where('Status', 'Active')
        ->limit(100)
        ->get();

    Opportunity::refreshCache()
        ->where('Stage', 'Closed Won')
        ->whereDate('CloseDate', '>=', now()->subDays(30))
        ->get();
})->hourly();
```

## Troubleshooting

### Cache Not Working

1. Check if caching is enabled:
```bash
php artisan tinker
>>> config('eloquent-salesforce-objects.query_cache.enabled')
=> true
```

2. Verify cache driver:
```bash
php artisan cache:clear
php artisan config:clear
```

3. Check cache driver supports tagging:
```php
// Redis, Memcached = YES (recommended)
// File, Database = LIMITED
// Array = NO (testing only)
```

### Stale Data

If you're seeing stale data:

1. Check if external changes are being made (Salesforce UI, other apps)
2. Consider implementing webhook invalidation (Phase 3 of implementation plan)
3. Reduce TTL for frequently changing objects
4. Use `refreshCache()` for critical queries

### High Cache Misses

Check statistics:
```bash
php artisan salesforce:cache-clear --stats
```

If hit rate is low:
- Queries might have dynamic parameters
- TTL might be too short
- Cache might be getting invalidated too frequently

## Best Practices

1. **Use longer TTL for stable data**
   - User records, Account hierarchies: 2-4 hours
   - Opportunities, Cases: 30 minutes - 1 hour
   - Real-time dashboards: 5-10 minutes or `withoutCache()`

2. **Tag important query groups**
   ```php
   Account::cacheTags(['sales-dashboard'])
       ->where('Status', 'Active')
       ->get();
   ```

3. **Warm cache for expensive queries**
   - Use scheduled tasks to refresh common queries
   - Prevents first-user slowness

4. **Monitor cache hit rates**
   - Run `php artisan salesforce:cache-clear --stats` regularly
   - Aim for 70%+ hit rate

5. **Use appropriate cache driver**
   - Development: File cache (simple)
   - Production: Redis (fast, supports tags)

## Cache Invalidation API

The package exposes an HTTP endpoint at `POST /api/salesforce/webhooks/cdc` that external systems can call to invalidate cached queries when data changes outside your Laravel application.

### Configuration

Enable the cache invalidation API in your `.env` file:

```env
SALESFORCE_WEBHOOK_INVALIDATION=true
SALESFORCE_WEBHOOK_SECRET=your-secure-random-secret-key
SALESFORCE_CACHE_INVALIDATION_STRATEGY=record  # Surgical invalidation
```

**Generate a secure secret:**
```bash
php artisan tinker --execute="echo Str::random(64);"
```

### How It Works

External systems POST a JSON payload to the endpoint describing what changed:

```json
POST /api/salesforce/webhooks/cdc
Content-Type: application/json
X-Salesforce-Webhook-Secret: your-secret-key

{
  "payload": {
    "ChangeEventHeader": {
      "entityName": "Opportunity",
      "recordIds": ["006xx000001ABC", "006xx000001XYZ"],
      "changeType": "UPDATE"
    }
  }
}
```

**With record-level strategy:**
- Only queries containing these specific records are invalidated
- Other Opportunity queries remain cached
- Maximum cache efficiency

**With object-level strategy:**
- ALL Opportunity queries are invalidated
- Simpler but less efficient

### Testing the Endpoint

**Health check:**
```bash
curl https://your-app.com/api/salesforce/webhooks/health
```

**Test invalidation:**
```bash
curl -X POST https://your-app.com/api/salesforce/webhooks/cdc \
  -H "Content-Type: application/json" \
  -H "X-Salesforce-Webhook-Secret: your-secret-key" \
  -d '{
    "payload": {
      "ChangeEventHeader": {
        "entityName": "Opportunity",
        "recordIds": ["006R300000F9YmbIAF"],
        "changeType": "UPDATE"
      }
    }
  }'
```

**Expected response:**
```json
{
  "success": true,
  "message": "Cache invalidated successfully",
  "entity": "Opportunity",
  "records_affected": 1,
  "invalidation_type": "record-level"
}
```

### Security

The endpoint validates requests using the `X-Salesforce-Webhook-Secret` header. Set `SALESFORCE_WEBHOOK_REQUIRE_VALIDATION=false` only for testing.

**Note:** The endpoint is automatically registered by the package. No manual route setup required!
