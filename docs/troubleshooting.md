# Troubleshooting Guide

Solutions to common issues when working with Eloquent Salesforce Objects.

## Table of Contents

- [Authentication Issues](#authentication-issues)
- [Query Problems](#query-problems)
- [Cache Issues](#cache-issues)
- [Performance Problems](#performance-problems)
- [Connection Errors](#connection-errors)
- [Field Mapping Issues](#field-mapping-issues)
- [Webhook Problems](#webhook-problems)
- [General Debugging](#general-debugging)

## Authentication Issues

### "Authentication Failed" Error

**Problem:** Unable to authenticate with Salesforce.

**Solutions:**

1. **Verify credentials in `.env`:**
   ```env
   CONSUMER_KEY=your_correct_consumer_key
   CONSUMER_SECRET=your_correct_consumer_secret
   USERNAME=your_salesforce_username
   PASSWORD=your_salesforce_password
   ```

2. **Check Salesforce Connected App settings:**
   - Ensure OAuth is enabled
   - Verify callback URL matches
   - Confirm API access is enabled

3. **Clear Laravel cache:**
   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```

4. **Check IP restrictions:**
   - In Salesforce, go to Connected App settings
   - Ensure your server IP is allowlisted
   - Or set "Relax IP restrictions"

5. **Verify user has API access:**
   - User profile must have "API Enabled" permission
   - Check in Salesforce: Setup → Users → User → Profile → System Permissions

### "Invalid Grant" Error

**Problem:** OAuth authentication fails with "invalid grant".

**Solutions:**

1. **Password security token:**
   ```env
   # If your Salesforce org requires a security token
   PASSWORD=your_password_with_security_token_appended
   ```

2. **Reset security token:**
   - Salesforce → Personal Settings → Reset My Security Token
   - Append token to password in `.env`

3. **Check password expiration:**
   - Ensure Salesforce password hasn't expired
   - Reset if necessary

### "Session Expired" Error

**Problem:** Authentication works initially but fails later.

**Solutions:**

1. **Enable token refresh:**
   ```php
   // config/forrest.php
   'authentication' => 'UserPassword',
   ```

2. **Check session timeout settings in Salesforce**

3. **Clear cached tokens:**
   ```bash
   php artisan cache:clear
   ```

## Query Problems

### "Object Does Not Exist" Error

**Problem:** Query fails saying object doesn't exist.

**Solutions:**

1. **Verify object API name:**
   ```php
   // Wrong
   protected $table = 'Accounts'; // Should be singular

   // Correct
   protected $table = 'Account';
   ```

2. **Check custom object naming:**
   ```php
   // Custom objects end with __c
   protected $table = 'Custom_Object__c';

   // Namespaced custom objects
   protected $table = 'namespace__Custom_Object__c';
   ```

3. **Verify user permissions:**
   - Ensure user has read access to the object
   - Check object-level security in Salesforce

### "Field Does Not Exist" Error

**Problem:** Query references a field that doesn't exist.

**Solutions:**

1. **Check field API name:**
   ```php
   // Wrong
   ->where('first_name', 'John') // snake_case doesn't exist

   // Correct
   ->where('FirstName', 'John')  // PascalCase (Salesforce default)
   ```

2. **Verify custom field naming:**
   ```php
   // Custom fields end with __c
   ->where('Custom_Field__c', 'value')
   ```

3. **Check if field exists:**
   ```php
   $fields = Account::describe();
   dump($fields); // See all available fields
   ```

### "SOQL Syntax Error"

**Problem:** Generated SOQL query has syntax errors.

**Solutions:**

1. **Debug the query:**
   ```php
   $soql = Account::where('Industry', 'Tech')->toSql();
   echo $soql; // View generated SOQL
   ```

2. **Check for special characters:**
   ```php
   // Wrong
   ->where('Name', "Company's Name") // Unescaped quote

   // Correct
   ->where('Name', "Company\'s Name") // Escaped quote
   ```

3. **Use proper operators:**
   ```php
   // SOQL uses = for equality
   ->where('Industry', '=', 'Technology')
   // or
   ->where('Industry', 'Technology')
   ```

## Cache Issues

### Stale Data from Cache

**Problem:** Queries return old cached data.

**Solutions:**

1. **Clear cache manually:**
   ```bash
   php artisan salesforce:cache-clear --all
   php artisan cache:clear
   ```

2. **Bypass cache for specific query:**
   ```php
   $accounts = Account::withoutCache()->get();
   ```

3. **Refresh cache:**
   ```php
   $accounts = Account::refreshCache()->get();
   ```

4. **Check auto-invalidation:**
   ```env
   SALESFORCE_AUTO_INVALIDATE_CACHE=true
   ```

5. **Reduce TTL:**
   ```env
   SALESFORCE_QUERY_CACHE_TTL=600  # 10 minutes instead of 1 hour
   ```

### Cache Not Working

**Problem:** Queries always hit Salesforce API, cache seems disabled.

**Solutions:**

1. **Verify caching is enabled:**
   ```bash
   php artisan tinker
   >>> config('eloquent-salesforce-objects.query_cache.enabled')
   => true
   ```

2. **Check cache driver supports tagging:**
   ```env
   # Use Redis or Memcached
   SALESFORCE_CACHE_DRIVER=redis

   # File and database have limited tagging support
   ```

3. **Clear config cache:**
   ```bash
   php artisan config:clear
   ```

4. **Test cache:**
   ```php
   use Illuminate\Support\Facades\Cache;

   Cache::tags(['test'])->put('key', 'value', 600);
   $value = Cache::tags(['test'])->get('key');
   dd($value); // Should be 'value'
   ```

### Cache Invalidation Not Working

**Problem:** Cache doesn't invalidate when records change.

**Solutions:**

1. **Check auto-invalidation setting:**
   ```php
   // config/eloquent-salesforce-objects.php
   'auto_invalidate_on_local_changes' => true,
   ```

2. **Verify invalidation strategy:**
   ```env
   SALESFORCE_CACHE_INVALIDATION_STRATEGY=record
   ```

3. **Check observers are registered:**
   ```php
   // Should happen automatically in SalesforceModel
   ```

4. **Manual invalidation:**
   ```php
   use Daikazu\EloquentSalesforceObjects\Support\QueryCache;

   app(QueryCache::class)->flushObject('Account');
   ```

## Performance Problems

### Slow Queries

**Problem:** Queries take too long to execute.

**Solutions:**

1. **Select only needed fields:**
   ```php
   // Slow - gets all fields
   Account::all();

   // Fast - gets specific fields
   Account::select(['Id', 'Name', 'Industry'])->get();
   ```

2. **Use indexes:** Ensure queried fields are indexed in Salesforce

3. **Limit results:**
   ```php
   Account::limit(100)->get();
   ```

4. **Enable caching:**
   ```env
   SALESFORCE_QUERY_CACHE=true
   ```

5. **Use eager loading:**
   ```php
   // N+1 problem
   $accounts = Account::all();
   foreach ($accounts as $account) {
       $contacts = $account->contacts; // Separate query each time
   }

   // Solution: Eager load
   $accounts = Account::with('contacts')->all();
   ```

### High API Usage

**Problem:** Consuming too many Salesforce API calls.

**Solutions:**

1. **Enable query caching:**
   ```env
   SALESFORCE_QUERY_CACHE=true
   SALESFORCE_QUERY_CACHE_TTL=3600
   ```

2. **Use bulk operations:**
   ```php
   // Instead of
   foreach ($data as $item) {
       Contact::create($item); // N API calls
   }

   // Use
   Contact::insert($data); // 1 API call
   ```

3. **Monitor API usage:**
   ```php
   use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;

   $adapter = app(SalesforceAdapter::class);
   $limits = $adapter->limits();
   dd($limits);
   ```

### Memory Issues

**Problem:** Running out of memory with large datasets.

**Solutions:**

1. **Use chunking:**
   ```php
   Account::chunk(200, function ($accounts) {
       foreach ($accounts as $account) {
           // Process
       }
   });
   ```

2. **Use pagination:**
   ```php
   $accounts = Account::paginate(50);
   ```

3. **Select fewer fields:**
   ```php
   Account::select(['Id', 'Name'])->chunk(500, function ($accounts) {
       // Process
   });
   ```

## Connection Errors

### "Could Not Connect to Salesforce" Error

**Problem:** Cannot establish connection.

**Solutions:**

1. **Check internet connectivity**

2. **Verify instance URL:**
   ```env
   SALESFORCE_INSTANCE_URL=https://your-instance.salesforce.com
   LOGIN_URL=https://login.salesforce.com  # Or https://test.salesforce.com for sandbox
   ```

3. **Check firewall/proxy settings**

4. **Verify SSL:**
   ```php
   // config/forrest.php
   'verify' => true, // Should be true in production
   ```

### "SSL Certificate Problem"

**Problem:** SSL verification fails.

**Solutions:**

1. **Update CA certificates:**
   ```bash
   # Ubuntu/Debian
   sudo apt-get update
   sudo apt-get install ca-certificates

   # Mac
   brew install openssl
   ```

2. **For local development only (NOT production):**
   ```php
   // config/forrest.php
   'verify' => env('APP_ENV') === 'production',
   ```

### "Timeout" Error

**Problem:** Requests timeout.

**Solutions:**

1. **Increase timeout in Forrest config:**
   ```php
   // config/forrest.php
   'timeout' => 120, // seconds
   ```

2. **Optimize query:**
   - Add WHERE clauses to limit results
   - Index fields used in WHERE clauses
   - Avoid complex subqueries

3. **Break into smaller operations:**
   ```php
   // Instead of one large query
   $accounts = Account::chunk(100, function ($chunk) {
       // Process chunk
   });
   ```

## Field Mapping Issues

### Field Mapping Not Working

**Problem:** Field name conversion doesn't work.

**Solutions:**

1. **Enable field mapping:**
   ```env
   SALESFORCE_ENABLE_FIELD_MAPPING=true
   ```

2. **Check naming convention:**
   ```env
   SALESFORCE_NAMING_CONVENTION=snake_case
   ```

3. **Clear config cache:**
   ```bash
   php artisan config:clear
   ```

4. **Define custom mappings:**
   ```php
   // config/eloquent-salesforce-objects.php
   'field_mappings' => [
       'account_name' => 'Name',
       'annual_revenue' => 'AnnualRevenue',
   ],
   ```

## Webhook Problems

### Webhooks Not Being Received

**Problem:** Salesforce webhooks aren't reaching your application.

**Solutions:**

1. **Check endpoint is accessible:**
   ```bash
   curl https://your-domain.com/api/salesforce/webhooks/health
   ```

2. **Verify webhook configuration in Salesforce:**
   - Correct endpoint URL
   - Active workflow/CDC subscription
   - No errors in delivery status

3. **Check firewall/security groups:**
   - Allow Salesforce IPs
   - Port 443 (HTTPS) is open

4. **View Laravel logs:**
   ```bash
   tail -f storage/logs/laravel.log
   ```

### Webhook Authentication Failures

**Problem:** Webhooks are rejected with 401 error.

**Solutions:**

1. **Verify webhook secret:**
   ```env
   SALESFORCE_WEBHOOK_SECRET=your-correct-secret
   ```

2. **Check header name:**
   - Should be `X-Salesforce-Webhook-Secret` or
   - `X-Salesforce-Signature` for HMAC

3. **Temporarily disable validation for testing:**
   ```env
   SALESFORCE_WEBHOOK_REQUIRE_VALIDATION=false
   ```

4. **Check logs for actual header received**

## General Debugging

### Enable Query Logging

```php
// config/eloquent-salesforce-objects.php
'enable_query_log' => true,
```

View queries in `storage/logs/laravel.log`.

### Enable Verbose Error Logging

```env
SALESFORCE_LOG_LEVEL=debug
SALESFORCE_THROW_EXCEPTIONS=true
```

### Test Connection

```php
// routes/web.php
Route::get('/test-salesforce', function () {
    try {
        $account = Account::first();
        return response()->json([
            'success' => true,
            'account' => $account,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ], 500);
    }
});
```

### Check Salesforce Limits

```php
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;

$adapter = app(SalesforceAdapter::class);
$limits = $adapter->limits();

return response()->json($limits);
```

### Debug SOQL Queries

```php
// See generated SOQL
$soql = Account::where('Industry', 'Tech')->toSql();
dd($soql);

// Test raw SOQL
$adapter = app(SalesforceAdapter::class);
$result = $adapter->query('SELECT Id, Name FROM Account LIMIT 5');
dd($result);
```

## Getting Additional Help

If you're still experiencing issues:

1. **Check package documentation:** [Full docs](README.md)
2. **Search GitHub issues:** [Issues](https://github.com/daikazu/eloquent-salesforce-objects/issues)
3. **Salesforce documentation:** [developer.salesforce.com](https://developer.salesforce.com)
4. **Open a new issue:** Provide:
   - Laravel version
   - PHP version
   - Package version
   - Error message and stack trace
   - Steps to reproduce

## Common Error Messages Reference

| Error | Cause | Solution |
|-------|-------|----------|
| `INVALID_SESSION_ID` | Session expired | Clear cache, re-authenticate |
| `MALFORMED_QUERY` | Invalid SOQL syntax | Check query with `toSql()` |
| `INVALID_FIELD` | Field doesn't exist | Verify field API name |
| `INVALID_TYPE` | Wrong object name | Check object API name |
| `INSUFFICIENT_ACCESS` | No permission | Check user profile permissions |
| `REQUEST_LIMIT_EXCEEDED` | Too many API calls | Enable caching, use bulk operations |
| `UNABLE_TO_LOCK_ROW` | Record being edited | Retry with exponential backoff |
| `ENTITY_IS_DELETED` | Record is deleted | Check with `withTrashed()` |

## Next Steps

- **[Configuration](configuration.md)** - Review configuration options
- **[Installation](installation.md)** - Verify setup
- **[GitHub Issues](https://github.com/daikazu/eloquent-salesforce-objects/issues)** - Report bugs or ask questions
