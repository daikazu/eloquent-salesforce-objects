# Salesforce Webhook Cache Invalidation Setup

This guide explains how to set up webhook-based cache invalidation using Salesforce Change Data Capture (CDC). With webhooks enabled, your cache automatically invalidates when changes happen in Salesforce - even from external sources like the Salesforce UI, mobile app, or other integrations.

## Why Use Webhooks?

**Without Webhooks (Phase 1 & 2):**
- Cache invalidates only when YOUR Laravel app makes changes
- External Salesforce changes (UI, other apps) don't invalidate cache
- Risk of stale data if others modify Salesforce

**With Webhooks (Phase 3):**
- Cache invalidates on ALL Salesforce changes, regardless of source
- Real-time synchronization
- No stale data issues
- Complete cache freshness
- **Surgical invalidation:** Only affected queries are cleared, not all queries for the object (with record-level strategy)

## Prerequisites

- Salesforce API Access
- Laravel application publicly accessible (or using ngrok for local development)
- Salesforce Change Data Capture enabled for your org

## Step 1: Configure Laravel Application

### 1.1 Environment Variables

Add these to your `.env` file:

```env
# Enable webhook-based cache invalidation
SALESFORCE_WEBHOOK_INVALIDATION=true

# Generate a secure secret for webhook validation
SALESFORCE_WEBHOOK_SECRET=your-secure-random-secret-key-here

# Cache invalidation strategy (record = surgical, object = broad)
SALESFORCE_CACHE_INVALIDATION_STRATEGY=record

# Optional: Disable validation for testing (NOT for production)
# SALESFORCE_WEBHOOK_REQUIRE_VALIDATION=false
```

**Invalidation Strategy:**
- `record` (default, recommended): Only invalidates queries containing the changed records (surgical)
- `object`: Invalidates all queries for the object type (broad)

With `record` strategy, if User A updates their Opportunity, only queries containing that specific Opportunity are invalidated. User B's Opportunity queries remain cached. This prevents cache thrashing in multi-user environments.

To generate a secure secret:
```bash
php artisan tinker
>>> Str::random(64)
```

### 1.2 Verify Configuration

Check that caching and webhooks are configured:

```bash
php artisan tinker
>>> config('eloquent-salesforce-objects.query_cache.enabled')
=> true
>>> config('eloquent-salesforce-objects.query_cache.webhook_invalidation')
=> true
>>> config('eloquent-salesforce-objects.query_cache.webhook_secret')
=> "your-secret-key..."
```

### 1.3 Test Webhook Endpoint

Your webhook endpoints are automatically registered:

- **Health Check:** `GET /api/salesforce/webhooks/health`
- **CDC Handler:** `POST /api/salesforce/webhooks/cdc`

Test the health check:
```bash
curl https://your-app.com/api/salesforce/webhooks/health
```

Expected response:
```json
{
  "status": "ok",
  "webhook_invalidation_enabled": true,
  "webhook_secret_configured": true,
  "timestamp": "2024-01-15T10:30:00Z"
}
```

## Step 2: Enable Change Data Capture in Salesforce

### 2.1 Using Salesforce Setup UI

1. Log in to Salesforce
2. Go to **Setup** → Search for "Change Data Capture"
3. Click **Change Data Capture**
4. Click **Edit** under "Available Entities"
5. Select the objects you want to monitor (e.g., Account, Contact, Opportunity)
6. Move them to "Selected Entities"
7. Click **Save**

**Tip:** You can select multiple objects at once by holding Ctrl (Windows) or Cmd (Mac) while clicking.

### 2.2 Verify CDC is Enabled

In Salesforce Setup:
1. Go to **Change Data Capture**
2. Verify your objects appear in "Selected Entities"

## Step 3: Configure Salesforce Platform Events (Alternative to CDC)

If your Salesforce edition doesn't support CDC, you can use Platform Events:

### 3.1 Create Platform Event

In Salesforce Setup:

1. **Setup** → **Platform Events** → **New Platform Event**
2. Name: `Cache_Invalidation__e`
3. Add fields:
   - `Object_Name__c` (Text, 255)
   - `Record_Id__c` (Text, 18)
   - `Change_Type__c` (Text, 50)

### 3.2 Create Apex Trigger

Create triggers for each object you want to monitor:

```apex
trigger AccountChangeEvent on Account (after insert, after update, after delete, after undelete) {
    List<Cache_Invalidation__e> events = new List<Cache_Invalidation__e>();

    String changeType = 'UPDATE';
    if (Trigger.isInsert) changeType = 'CREATE';
    if (Trigger.isDelete) changeType = 'DELETE';
    if (Trigger.isUndelete) changeType = 'UNDELETE';

    Set<Id> recordIds = new Set<Id>();
    if (Trigger.new != null) {
        for (Account acc : Trigger.new) {
            recordIds.add(acc.Id);
        }
    } else if (Trigger.old != null) {
        for (Account acc : Trigger.old) {
            recordIds.add(acc.Id);
        }
    }

    for (Id recordId : recordIds) {
        events.add(new Cache_Invalidation__e(
            Object_Name__c = 'Account',
            Record_Id__c = recordId,
            Change_Type__c = changeType
        ));
    }

    if (!events.isEmpty()) {
        EventBus.publish(events);
    }
}
```

## Step 4: Set Up Outbound Message (Simplest Method)

For simpler setups, use Salesforce Workflow Rules with Outbound Messages:

### 4.1 Create Outbound Message

1. **Setup** → **Workflow Rules** → **New Rule**
2. Select object (e.g., Account)
3. Rule Criteria: Choose when to trigger (e.g., "Every time a record is created or edited")
4. Add Workflow Action → **New Outbound Message**
5. Name: `Account Change Webhook`
6. Endpoint URL: `https://your-app.com/api/salesforce/webhooks/cdc`
7. Add secret to URL or custom header (see security section)
8. Select fields to send (at minimum: Id)
9. **Save & Activate**

### 4.2 Activate Workflow Rule

1. Ensure workflow rule is **Active**
2. Test by creating/updating a record in Salesforce

## Step 5: Security Configuration

### Option 1: Secret Token in Header (Recommended)

Configure Salesforce to send a custom header:

In your Outbound Message or CDC configuration, add:
- Header Name: `X-Salesforce-Webhook-Secret`
- Header Value: Your secret from `.env`

### Option 2: HMAC Signature (Most Secure)

For CDC webhooks, Salesforce can sign requests with HMAC:

1. In Salesforce CDC setup, enable "Require Signature"
2. Provide your webhook secret
3. Salesforce sends `X-Salesforce-Signature` header
4. Our middleware automatically validates it

### Option 3: IP Allowlist

Restrict webhook access to Salesforce IPs:

```php
// In your Laravel middleware
Route::post('/api/salesforce/webhooks/cdc')
    ->middleware(['salesforce.webhook', 'throttle:60,1'])
    ->name('salesforce.webhook.cdc');
```

## Step 6: Testing

### 6.1 Test with Postman/cURL

Test CDC webhook endpoint:

```bash
curl -X POST https://your-app.com/api/salesforce/webhooks/cdc \
  -H "Content-Type: application/json" \
  -H "X-Salesforce-Webhook-Secret: your-secret-key" \
  -d '{
    "schema": "...",
    "payload": {
      "ChangeEventHeader": {
        "entityName": "Account",
        "recordIds": ["001xx000003DGb2AAG"],
        "changeType": "UPDATE",
        "changeOrigin": "com.salesforce.api.soap"
      },
      "Id": "001xx000003DGb2AAG",
      "Name": "Test Account"
    },
    "event": {
      "replayId": 123456
    }
  }'
```

Expected response:
```json
{
  "success": true,
  "message": "Cache invalidated successfully",
  "entity": "Account",
  "records_affected": 1,
  "invalidation_type": "record-level"
}
```

The `invalidation_type` field shows which strategy was used:
- `record-level`: Only queries containing the specific records were invalidated (surgical)
- `object-level`: All queries for the object were invalidated (broad)

### 6.2 Test End-to-End

1. **Cache a query in Laravel:**
   ```php
   $accounts = Account::where('Industry', 'Tech')->get();
   // This is now cached
   ```

2. **Update record in Salesforce UI:**
   - Go to an Account record
   - Change Industry to "Finance"
   - Save

3. **Query again in Laravel:**
   ```php
   $accounts = Account::where('Industry', 'Tech')->get();
   // Should hit API (cache was invalidated by webhook)
   ```

4. **Check logs:**
   ```bash
   tail -f storage/logs/laravel.log | grep "CDC webhook"
   ```

### 6.3 Monitor Webhook Deliveries

In Salesforce:
1. **Setup** → **Outbound Messages**
2. Click on your message
3. View **Delivery Status**
4. Check for failed deliveries

## Step 7: Local Development with ngrok

For local testing, expose your Laravel app:

```bash
# Install ngrok
brew install ngrok  # macOS
# or download from https://ngrok.com

# Start your Laravel app
php artisan serve

# In another terminal, expose it
ngrok http 8000

# Use the ngrok URL in Salesforce
# Example: https://abc123.ngrok.io/api/salesforce/webhooks/cdc
```

## Troubleshooting

### Webhooks Not Being Received

1. **Check Laravel logs:**
   ```bash
   tail -f storage/logs/laravel.log
   ```

2. **Verify endpoint is accessible:**
   ```bash
   curl https://your-app.com/api/salesforce/webhooks/health
   ```

3. **Check Salesforce Outbound Message status:**
   - Setup → Outbound Messages → View Delivery Status

4. **Verify firewall/security groups:**
   - Ensure your server accepts requests from Salesforce IPs

### Authentication Failures

1. **Check secret is configured:**
   ```bash
   php artisan tinker
   >>> config('eloquent-salesforce-objects.query_cache.webhook_secret')
   ```

2. **Verify header name matches:**
   - Controller expects: `X-Salesforce-Webhook-Secret`
   - Or signature in: `X-Salesforce-Signature`

3. **Temporarily disable validation for testing:**
   ```env
   SALESFORCE_WEBHOOK_REQUIRE_VALIDATION=false
   ```

### Cache Not Invalidating

1. **Check webhook was processed:**
   ```bash
   grep "CDC webhook processed" storage/logs/laravel.log
   ```

2. **Verify object name matches:**
   - CDC sends: `Account`
   - Your model uses: `Account` (must match exactly)

3. **Check cache driver supports tags:**
   - Redis, Memcached: Yes
   - File, Database: Limited
   - Array: No (testing only)

## Production Recommendations

1. **Use HTTPS only** - Never use HTTP for webhooks
2. **Enable signature validation** - Don't rely on secret token alone
3. **Monitor webhook failures** - Set up alerts for failed deliveries
4. **Rate limiting** - Protect endpoint from abuse
5. **Queue processing** - For high-volume webhooks:

```php
// In controller
dispatch(new InvalidateCacheJob($entityName, $recordIds));
```

6. **Logging** - Log all webhook activity for auditing
7. **Retry logic** - Salesforce retries failed webhooks automatically

## Advanced: Custom Webhook Handler

For custom logic beyond cache invalidation:

```php
// app/Listeners/SalesforceWebhookListener.php
namespace App\Listeners;

use Illuminate\Support\Facades\Log;

class SalesforceWebhookListener
{
    public function handle($payload)
    {
        $entity = $payload['payload']['ChangeEventHeader']['entityName'];
        $changeType = $payload['payload']['ChangeEventHeader']['changeType'];

        // Custom logic here
        if ($entity === 'Opportunity' && $changeType === 'UPDATE') {
            // Trigger dashboard refresh
            // Send notifications
            // Update analytics
        }
    }
}
```

## Next Steps

- Review [Query Caching](caching.md) for general cache usage
- Review [CACHING_IMPLEMENTATION_SUMMARY.md](../CACHING_IMPLEMENTATION_SUMMARY.md) for technical details
- Set up monitoring for webhook health
- Consider Phase 4: Streaming API listener for very high-frequency changes

## Support

For issues related to:
- **Laravel package**: Open issue on GitHub
- **Salesforce CDC setup**: Consult Salesforce documentation
- **Webhook delivery**: Check Salesforce Setup → Outbound Messages
