# Salesforce Webhook Cache Invalidation Setup

> **⚠️ Important Note about Workflow Rules**
> Salesforce has deprecated Workflow Rules and Outbound Messages in favor of **Flow Builder** and **Platform Events**. This guide has been updated to use the modern approach. If you're currently using Workflow Rules, see the [Migration from Workflow Rules](#migration-from-workflow-rules-deprecated) section below.

This guide explains how to set up webhook-based cache invalidation using Salesforce Change Data Capture (CDC) or Platform Events with Flow Builder. With webhooks enabled, your cache automatically invalidates when changes happen in Salesforce - even from external sources like the Salesforce UI, mobile app, or other integrations.

## Quick Start Guide

Choose the best approach for your Salesforce edition:

| Your Salesforce Edition | Recommended Approach | Setup Complexity |
|------------------------|---------------------|------------------|
| **Enterprise/Unlimited** | [Change Data Capture](#step-2-enable-change-data-capture-in-salesforce) (Step 2) | ⭐ Easy |
| **Enterprise+ with Event Relay** | [Flow + Event Relay](#option-a-use-salesforce-event-relay-recommended-for-enterprise) (Step 4, Option A) | ⭐⭐ Moderate |
| **Professional/Any Edition** | [Flow + Apex Callout](#option-b-use-apex-trigger--http-callout-universal) (Step 4, Option B) | ⭐⭐⭐ Advanced |

**New to Salesforce webhooks?** Start with Change Data Capture (Step 2) if your edition supports it - it's the easiest and most reliable.

## Table of Contents

- [Why Use Webhooks?](#why-use-webhooks)
- [Prerequisites](#prerequisites)
- [Step 1: Configure Laravel Application](#step-1-configure-laravel-application)
- [Step 2: Enable Change Data Capture](#step-2-enable-change-data-capture-in-salesforce) ⭐ Recommended
- [Step 3: Configure Platform Events](#step-3-configure-salesforce-platform-events-alternative-to-cdc) (Alternative)
- [Step 4: Set Up Flow Builder](#step-4-set-up-flow-builder-with-platform-events-modern-approach) (Modern)
- [Step 5: Security Configuration](#step-5-security-configuration)
- [Step 6: Testing](#step-6-testing)
- [Step 7: Local Development](#step-7-local-development-with-ngrok)
- [Troubleshooting](#troubleshooting)
- [Production Recommendations](#production-recommendations)
- [Migration from Workflow Rules](#migration-from-workflow-rules-deprecated)

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

## Step 4: Set Up Flow Builder with Platform Events (Modern Approach)

**Note:** Salesforce has deprecated Workflow Rules in favor of Flow Builder. Use this modern approach for new implementations.

### 4.1 Create Platform Event (if not using CDC)

If you created the Platform Event in Step 3, you can reuse it. Otherwise:

1. **Setup** → Search for **Platform Events** → **New Platform Event**
2. **Label:** `Cache Invalidation Event`
3. **Plural Label:** `Cache Invalidation Events`
4. **Object Name:** `Cache_Invalidation__e`
5. Click **Save**
6. Add Custom Fields:
   - **Object_Name__c** (Text, Length: 255, Required)
   - **Record_Id__c** (Text, Length: 18, Required)
   - **Change_Type__c** (Text, Length: 50, Required)
   - **Timestamp__c** (Date/Time, Optional)
7. **Save**

### 4.2 Create a Record-Triggered Flow

For each object you want to monitor (e.g., Account):

1. **Setup** → Search for **Flows** → **New Flow**
2. Select **Record-Triggered Flow** → **Next**
3. **Configure Start:**
   - **Object:** Account
   - **Trigger:** A record is created or updated
   - **Condition Requirements:** All Conditions Are Met (OR)
     - Add condition: `Created Date` `Is Changed` `True` (for new records)
     - Click "Add Condition" → `Last Modified Date` `Is Changed` `True` (for updates)
   - **Optimize for:** Actions and Related Records
4. Click **Done**

5. **Add Action - Create Platform Event:**
   - Click the **+** icon → **Action** → **Create Records**
   - **Label:** Publish Cache Invalidation Event
   - **How Many Records:** One
   - **Use separate resources:** Selected
   - **Object:** `Cache_Invalidation__e`
   - **Set Field Values:**
     - `Object_Name__c` = `Account` (use Text Template with literal "Account")
     - `Record_Id__c` = `{!$Record.Id}` (Field Reference)
     - `Change_Type__c` = `UPDATE` (or use formula to determine CREATE vs UPDATE)
     - `Timestamp__c` = `{!$Flow.CurrentDateTime}` (optional)
   - **Done**

6. **Save the Flow:**
   - **Flow Label:** Account Cache Invalidation
   - **Flow API Name:** Account_Cache_Invalidation
   - **Description:** Publishes platform event when Account records change
   - **Save**

7. **Activate the Flow:**
   - Click **Activate**

### 4.3 Subscribe to Platform Events in Laravel

The package's CDC webhook endpoint (`/api/salesforce/webhooks/cdc`) can handle both Change Data Capture events AND Platform Events with the same format.

However, you'll need to configure Salesforce to push Platform Events to your endpoint. You have two options:

#### Option A: Use Salesforce Event Relay (Recommended for Enterprise+)

Available in Enterprise Edition and above:

1. **Setup** → Search for **Event Relay** → **New Event Relay Config**
2. **Event Relay Label:** Laravel Cache Invalidation
3. **Destination URL:** `https://your-app.com/api/salesforce/webhooks/cdc`
4. **User:** Select a user with API access
5. **Event Channels:** Add your Platform Event (`Cache_Invalidation__e`)
6. **State:** Active
7. **Save**

Event Relay will automatically push Platform Events to your Laravel app in real-time.

#### Option B: Use Apex Trigger + HTTP Callout (Universal)

Works in all Salesforce editions:

1. **Create Remote Site Setting:**
   - **Setup** → **Remote Site Settings** → **New Remote Site**
   - **Name:** Laravel_Webhook_Endpoint
   - **URL:** `https://your-app.com`
   - **Active:** Checked
   - **Save**

2. **Create Apex Class for HTTP Callout:**

```apex
public class CacheInvalidationCallout {
    @future(callout=true)
    public static void sendWebhook(String objectName, String recordId, String changeType) {
        // Build the payload
        Map<String, Object> payload = new Map<String, Object>{
            'schema' => 'cache-invalidation-v1',
            'payload' => new Map<String, Object>{
                'ChangeEventHeader' => new Map<String, Object>{
                    'entityName' => objectName,
                    'recordIds' => new List<String>{ recordId },
                    'changeType' => changeType,
                    'changeOrigin' => 'com.salesforce.api.soap'
                },
                'Id' => recordId
            },
            'event' => new Map<String, Object>{
                'replayId' => System.currentTimeMillis()
            }
        };

        // Make HTTP callout
        HttpRequest req = new HttpRequest();
        req.setEndpoint('https://your-app.com/api/salesforce/webhooks/cdc');
        req.setMethod('POST');
        req.setHeader('Content-Type', 'application/json');
        req.setHeader('X-Salesforce-Webhook-Secret', 'YOUR_WEBHOOK_SECRET_HERE');
        req.setBody(JSON.serialize(payload));
        req.setTimeout(120000);

        Http http = new Http();
        try {
            HttpResponse res = http.send(req);
            System.debug('Webhook response: ' + res.getStatusCode() + ' - ' + res.getBody());
        } catch(Exception e) {
            System.debug('Webhook error: ' + e.getMessage());
        }
    }
}
```

3. **Create Platform Event Trigger:**

```apex
trigger CacheInvalidationTrigger on Cache_Invalidation__e (after insert) {
    for (Cache_Invalidation__e event : Trigger.New) {
        CacheInvalidationCallout.sendWebhook(
            event.Object_Name__c,
            event.Record_Id__c,
            event.Change_Type__c
        );
    }
}
```

### 4.4 Option C: External Service with Flow (No Apex Required)

**Recommended for:** Users who want to use Flow Builder without writing Apex code.

External Services allow you to define HTTP callouts declaratively and use them in Flow Builder. This is the modern, no-code approach.

#### Step 1: Create OpenAPI Specification

Salesforce External Services require an OpenAPI 2.0 (Swagger) specification. Create a file named `salesforce-webhook-api.json`:

```json
{
  "swagger": "2.0",
  "info": {
    "title": "Laravel Salesforce Webhook API",
    "description": "API for cache invalidation webhooks",
    "version": "1.0.0"
  },
  "host": "your-app.com",
  "basePath": "/api/salesforce/webhooks",
  "schemes": ["https"],
  "consumes": ["application/json"],
  "produces": ["application/json"],
  "securityDefinitions": {
    "api_key": {
      "type": "apiKey",
      "name": "X-Salesforce-Webhook-Secret",
      "in": "header"
    }
  },
  "security": [
    {
      "api_key": []
    }
  ],
  "paths": {
    "/cdc": {
      "post": {
        "summary": "Send Cache Invalidation Event",
        "description": "Invalidates cache for specific Salesforce records",
        "operationId": "invalidateCache",
        "parameters": [
          {
            "name": "body",
            "in": "body",
            "required": true,
            "schema": {
              "$ref": "#/definitions/CacheInvalidationRequest"
            }
          }
        ],
        "responses": {
          "200": {
            "description": "Success",
            "schema": {
              "$ref": "#/definitions/CacheInvalidationResponse"
            }
          },
          "401": {
            "description": "Unauthorized"
          },
          "500": {
            "description": "Server Error"
          }
        }
      }
    }
  },
  "definitions": {
    "CacheInvalidationRequest": {
      "type": "object",
      "required": ["payload"],
      "properties": {
        "schema": {
          "type": "string",
          "default": "cache-invalidation-v1"
        },
        "payload": {
          "$ref": "#/definitions/Payload"
        },
        "event": {
          "$ref": "#/definitions/Event"
        }
      }
    },
    "Payload": {
      "type": "object",
      "required": ["ChangeEventHeader"],
      "properties": {
        "ChangeEventHeader": {
          "$ref": "#/definitions/ChangeEventHeader"
        },
        "Id": {
          "type": "string",
          "description": "Salesforce Record ID"
        }
      }
    },
    "ChangeEventHeader": {
      "type": "object",
      "required": ["entityName", "recordIds", "changeType"],
      "properties": {
        "entityName": {
          "type": "string",
          "description": "Salesforce object name (e.g., Account, Contact)"
        },
        "recordIds": {
          "type": "array",
          "items": {
            "type": "string"
          },
          "description": "Array of record IDs that changed"
        },
        "changeType": {
          "type": "string",
          "enum": ["CREATE", "UPDATE", "DELETE", "UNDELETE"],
          "description": "Type of change that occurred"
        },
        "changeOrigin": {
          "type": "string",
          "default": "com.salesforce.api.soap"
        }
      }
    },
    "Event": {
      "type": "object",
      "properties": {
        "replayId": {
          "type": "integer",
          "format": "int64"
        }
      }
    },
    "CacheInvalidationResponse": {
      "type": "object",
      "properties": {
        "success": {
          "type": "boolean"
        },
        "message": {
          "type": "string"
        },
        "entity": {
          "type": "string"
        },
        "records_affected": {
          "type": "integer"
        },
        "invalidation_type": {
          "type": "string"
        }
      }
    }
  }
}
```

**Important:** Replace `your-app.com` with your actual domain.

#### Step 2: Register External Service in Salesforce

1. **Setup** → Search for **External Services** → **Add an External Service**

2. **External Service Details:**
   - **External Service Name:** `Laravel_Cache_Invalidation`
   - **Description:** `Webhook endpoint for cache invalidation`

3. **Service Schema:**
   - **Select:** Complete OpenAPI Spec from URL or File
   - **Choose:** Upload File
   - **Select the JSON file** you created above
   - Click **Next**

4. **Review Operations:**
   - You should see the operation: `invalidateCache`
   - Review the request/response schemas
   - Click **Next**

5. **Named Credential:**
   - **Option 1: Create New Named Credential** (Recommended)
     - **Named Credential Label:** `Laravel_App_Webhook`
     - **URL:** `https://your-app.com`
     - **Authentication:** Named Principal
     - **Authentication Protocol:** Custom
     - **Custom Headers:**
       - **Name:** `X-Salesforce-Webhook-Secret`
       - **Value:** Your webhook secret from `.env`
     - Click **Save**

   - **Option 2: Use Existing Named Credential**
     - Select from dropdown if you already have one configured

6. **Complete Setup:**
   - Click **Done**

#### Step 3: Configure Named Credential (if created new)

After creating the External Service:

1. **Setup** → **Named Credentials** → Find **Laravel_App_Webhook**

2. **Edit Authentication Settings:**
   - **Identity Type:** Named Principal
   - **Authentication Protocol:** No Authentication (we're using custom header)
   - **Generate Authorization Header:** Unchecked
   - **Custom Headers:**
     - Add: `X-Salesforce-Webhook-Secret` = `your-secret-key-here`

3. **Callout Options:**
   - Check **Allow Formulas in HTTP Header**
   - Check **Allow Formulas in HTTP Body**

4. **Save**

#### Step 4: Update Your Record-Triggered Flow

Now you can use the External Service in Flow Builder:

1. **Open your Flow** from Step 4.2 (or create new Record-Triggered Flow)

2. **Instead of creating a Platform Event**, add an External Service action:
   - Click the **+** icon → **Action**
   - Search for your External Service: **Laravel Cache Invalidation**
   - Select the action: **invalidateCache**

3. **Configure the Action:**
   - **Label:** `Call Laravel Webhook`
   - **API Name:** `Call_Laravel_Webhook`

   - **Set Input Values** (map flow variables to API request):
     - `schema`: Set to text literal `"cache-invalidation-v1"`
     - `payload` → **New Resource** → **Apex-Defined** → Type the payload structure:
       - `ChangeEventHeader`:
         - `entityName`: Text literal `"Account"` (or use variable for dynamic object)
         - `recordIds`: **New Resource** → **Collection** → Add `{!$Record.Id}`
         - `changeType`: Decision/Formula to determine CREATE vs UPDATE vs DELETE
           ```
           IF(ISNEW(), "CREATE", "UPDATE")
           ```
         - `changeOrigin`: `"com.salesforce.api.soap"`
       - `Id`: `{!$Record.Id}`
     - `event`:
       - `replayId`: Leave blank or use formula for timestamp

4. **Save and Activate**

#### Step 5: Simplified Flow Setup (Easier Approach)

For a simpler setup, create flow variables:

1. **Create Variables:**
   - **Variable: entityName** (Text) = `"Account"`
   - **Variable: recordId** (Text) = `{!$Record.Id}`
   - **Variable: changeType** (Text) = Formula: `IF(ISNEW(), "CREATE", "UPDATE")`

2. **Create the Request Body as Text Template:**
   - **New Resource** → **Text Template**
   - **API Name:** `WebhookPayload`
   - **Body:**
   ```json
   {
     "schema": "cache-invalidation-v1",
     "payload": {
       "ChangeEventHeader": {
         "entityName": "{!entityName}",
         "recordIds": ["{!recordId}"],
         "changeType": "{!changeType}",
         "changeOrigin": "com.salesforce.api.soap"
       },
       "Id": "{!recordId}"
     },
     "event": {
       "replayId": {!$Flow.CurrentDateTime}
     }
   }
   ```

3. **Add External Service Action:**
   - Use the External Service action
   - Pass the `WebhookPayload` text template as the body

#### Step 6: Test the External Service

1. **Test in Salesforce Workbench:**
   - Setup → Developer Console → Anonymous Apex
   ```apex
   ExternalService.Laravel_Cache_Invalidation.invalidateCache(
     new Map<String, Object>{
       'schema' => 'cache-invalidation-v1',
       'payload' => new Map<String, Object>{
         'ChangeEventHeader' => new Map<String, Object>{
           'entityName' => 'Account',
           'recordIds' => new List<String>{'001xx000003DGb2AAG'},
           'changeType' => 'UPDATE',
           'changeOrigin' => 'com.salesforce.api.soap'
         },
         'Id' => '001xx000003DGb2AAG'
       }
     }
   );
   ```

2. **Test the Flow:**
   - Go to an Account record
   - Edit a field
   - Save
   - Check Debug Logs for flow execution
   - Check Laravel logs for webhook receipt

#### Benefits of External Service Approach

✅ **No Apex Code Required** - Fully declarative
✅ **Built-in Error Handling** - Flow Builder handles retries
✅ **Easy to Maintain** - Visual configuration
✅ **Versioning** - Can update API spec without changing flows
✅ **Reusable** - One External Service, many flows
✅ **Debugging** - Better error messages in Flow debug
✅ **Security** - Named Credentials managed centrally

#### Troubleshooting External Services

**"Invalid OpenAPI Schema"**
- Ensure JSON is valid (use https://jsonlint.com)
- Salesforce only supports OpenAPI 2.0 (Swagger), not 3.0
- All required fields must be present

**"Callout Failed"**
- Check Named Credential URL is correct
- Verify SSL certificate is valid
- Test endpoint with Postman first
- Check remote site settings

**"Authentication Failed"**
- Verify webhook secret in Named Credential custom headers
- Ensure header name matches exactly: `X-Salesforce-Webhook-Secret`
- Check Laravel logs for auth errors

For simplicity, we recommend using Platform Events + Apex Trigger approach (Option B above) or Event Relay (Option A) for Enterprise customers, unless you specifically need the External Service approach for governance or no-code requirements.

### 4.5 Test the Flow

1. **Test by modifying a record:**
   - Go to an Account in Salesforce
   - Edit any field
   - Save

2. **Check Debug Logs:**
   - **Setup** → **Debug Logs** → **New** → Select your user
   - Make another change
   - View the debug log to see the flow execution

3. **Verify webhook received:**
   ```bash
   tail -f storage/logs/laravel.log | grep "CDC webhook"
   ```

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

## Migration from Workflow Rules (Deprecated)

If you're currently using the deprecated Workflow Rules with Outbound Messages, here's how to migrate to the modern Flow Builder approach:

### Why Migrate?

- **Workflow Rules are deprecated** - Salesforce will eventually remove them
- **Flow Builder is more powerful** - Better debugging, testing, and maintenance
- **Platform Events are more reliable** - Better error handling and retry logic
- **Future-proof your integration** - Stay on supported technology

### Migration Steps

#### Step 1: Identify Current Workflow Rules

1. **Setup** → **Workflow Rules**
2. Find all rules that send Outbound Messages for cache invalidation
3. Document which objects they monitor

#### Step 2: Create Replacement Flows

For each Workflow Rule:

1. **Note the object** (e.g., Account, Contact, Opportunity)
2. **Note the trigger criteria** (created, updated, specific field changes)
3. Follow [Step 4.2: Create a Record-Triggered Flow](#42-create-a-record-triggered-flow) above
4. **Test thoroughly** before deactivating the Workflow Rule

#### Step 3: Set Up Platform Event Infrastructure

Follow either:
- [Option A: Event Relay](#option-a-use-salesforce-event-relay-recommended-for-enterprise) (for Enterprise+ editions)
- [Option B: Apex Trigger + HTTP Callout](#option-b-use-apex-trigger--http-callout-universal) (for all editions)

#### Step 4: Test Side-by-Side

1. **Keep Workflow Rule active** initially
2. **Activate the new Flow**
3. **Monitor both** for a few days
4. **Verify** webhooks are received for both approaches

#### Step 5: Deactivate Workflow Rules

Once confident:

1. **Deactivate** the Workflow Rule (don't delete yet)
2. **Monitor** for any issues
3. After 30 days of stable operation, **delete** the old Workflow Rule

### Quick Comparison

| Feature | Workflow Rules (Deprecated) | Flow Builder (Modern) |
|---------|----------------------------|----------------------|
| **Status** | Deprecated by Salesforce | Actively supported |
| **Debugging** | Limited | Comprehensive debug tools |
| **Testing** | Manual only | Built-in test functionality |
| **Conditions** | Basic criteria | Complex logic, formulas, decisions |
| **Maintenance** | Harder to understand | Visual, easier to maintain |
| **Delivery Method** | Outbound Message | Platform Events or Event Relay |
| **Retry Logic** | Automatic (limited visibility) | Configurable with better monitoring |
| **Future Support** | ❌ Will be removed | ✅ Salesforce's future |

### Legacy Outbound Message Documentation

If you must continue using Workflow Rules temporarily, here's the legacy setup:

<details>
<summary>Click to expand legacy Workflow Rules setup (not recommended)</summary>

#### Create Outbound Message (Legacy Method)

⚠️ **Deprecated:** This method will be removed by Salesforce in a future release.

1. **Setup** → **Workflow Rules** → **New Rule**
2. Select object (e.g., Account)
3. Rule Criteria: Choose when to trigger (e.g., "Every time a record is created or edited")
4. Add Workflow Action → **New Outbound Message**
5. Name: `Account Change Webhook`
6. Endpoint URL: `https://your-app.com/api/salesforce/webhooks/cdc`
7. Add secret to URL or custom header (see security section)
8. Select fields to send (at minimum: Id)
9. **Save & Activate**

#### Activate Workflow Rule

1. Ensure workflow rule is **Active**
2. Test by creating/updating a record in Salesforce

**Note:** This approach still works but should be replaced with Flow Builder as soon as possible.

</details>

## Support

For issues related to:
- **Laravel package**: Open issue on GitHub
- **Salesforce CDC setup**: Consult Salesforce documentation
- **Flow Builder setup**: Salesforce Trailhead has excellent Flow Builder training
- **Webhook delivery**: Check Salesforce Setup → Event Relay or Debug Logs for Flow executions
