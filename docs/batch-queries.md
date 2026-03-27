# Batch Queries

Execute multiple SOQL queries in a single API call using the Salesforce Composite Batch API.

## Table of Contents

- [Overview](#overview)
- [Basic Usage](#basic-usage)
- [Adding Queries](#adding-queries)
- [Working with Results](#working-with-results)
- [Error Handling](#error-handling)
- [Configuration](#configuration)
- [Examples](#examples)

## Overview

Batch queries let you send up to 25 SOQL queries to Salesforce in a single HTTP request. This is useful when you need to fetch data from multiple objects at once — instead of making 5 separate API calls, you make 1.

```php
use Daikazu\EloquentSalesforceObjects\Database\SalesforceBatch;

$results = SalesforceBatch::new()
    ->add('leads', Lead::where('Status', 'Open'))
    ->add('contacts', Contact::where('AccountId', $accountId))
    ->add('opps', Opportunity::where('StageName', 'Closed Won'))
    ->run();
```

**When to use batch queries:**
- Loading data for a dashboard (multiple objects at once)
- Fetching related data that can't be expressed in a single SOQL query
- Reducing API call count when approaching Salesforce limits

**When NOT to use batch queries:**
- Single queries — no benefit, just added complexity
- Bulk inserts/updates/deletes — use [Bulk Operations](bulk-operations.md) instead

## Basic Usage

```php
use Daikazu\EloquentSalesforceObjects\Database\SalesforceBatch;

// 1. Create a batch and add named queries
$results = SalesforceBatch::new()
    ->add('accounts', Account::where('Industry', 'Technology')->limit(10))
    ->add('contacts', Contact::where('Email', '!=', null)->limit(50))
    ->run();

// 2. Retrieve results by name
$accounts = $results->get('accounts');  // Collection of Account models
$contacts = $results->get('contacts');  // Collection of Contact models

// 3. Use them like any Eloquent collection
foreach ($accounts as $account) {
    echo $account->Name;
}
```

## Adding Queries

### Eloquent Builders

Pass any Eloquent-style query builder. Results are returned as model instances:

```php
$batch = SalesforceBatch::new()
    ->add('active_leads', Lead::where('Status', 'Open')->orderBy('CreatedDate', 'desc'))
    ->add('vip_accounts', Account::where('AnnualRevenue', '>', 1000000)->limit(20))
    ->add('recent_opps', Opportunity::where('CloseDate', '>', '2025-01-01'));
```

### Raw SOQL Strings

For complex queries or when you don't have a model, pass a raw SOQL string. Results are returned as `stdClass` objects:

```php
$batch = SalesforceBatch::new()
    ->add('lead_count', "SELECT COUNT(Id) total FROM Lead WHERE Status = 'Open'")
    ->add('custom', "SELECT Id, Name, Custom_Field__c FROM Custom_Object__c LIMIT 10");
```

### Mixing Both

You can mix Eloquent builders and raw SOQL in the same batch:

```php
$results = SalesforceBatch::new()
    ->add('accounts', Account::where('Industry', 'Technology'))
    ->add('stats', "SELECT Industry, COUNT(Id) total FROM Account GROUP BY Industry")
    ->run();

$accounts = $results->get('accounts');  // Collection of Account models
$stats = $results->get('stats');        // Collection of stdClass objects
```

### Naming Rules

Each query needs a unique name. Use descriptive names — you'll use them to retrieve results:

```php
// Good — clear what each query returns
->add('open_leads', Lead::where('Status', 'Open'))
->add('closed_won_opps', Opportunity::where('StageName', 'Closed Won'))

// Bad — unclear, easy to mix up
->add('q1', Lead::where('Status', 'Open'))
->add('q2', Opportunity::where('StageName', 'Closed Won'))
```

## Working with Results

The `run()` method returns a `SalesforceBatchResult` object:

### Getting Data

```php
$results = SalesforceBatch::new()
    ->add('leads', Lead::where('Status', 'Open'))
    ->run();

// Get a Collection of model instances (or null if the query failed)
$leads = $results->get('leads');
```

### Checking Success/Failure

Each query succeeds or fails independently — a failed query doesn't affect the others:

```php
$results->successful('leads');  // true if the query succeeded
$results->failed('leads');      // true if the query failed
$results->allSuccessful();      // true if ALL queries succeeded
```

### Error Details

```php
if ($results->failed('leads')) {
    $error = $results->error('leads');
    // ['message' => 'MALFORMED_QUERY: ...', 'errorCode' => '...']
}
```

### Listing Results

```php
$results->names();     // ['leads', 'contacts', 'opps'] — all query names
$results->failures();  // ['opps'] — names of failed queries only
```

## Error Handling

Batch queries use **partial results** — each query succeeds or fails independently. This means:

```php
$results = SalesforceBatch::new()
    ->add('good_query', Account::where('Name', 'Acme'))
    ->add('bad_query', "SELECT BadField FROM FakeObject__c")
    ->run();

$results->successful('good_query');  // true — still works
$results->failed('bad_query');       // true — only this one failed
$results->get('good_query');         // Collection with results
$results->get('bad_query');          // null
$results->error('bad_query');        // ['message' => 'INVALID_TYPE: ...']
```

If the entire HTTP call to Salesforce fails (e.g., authentication error, network timeout), all queries in that chunk are marked as failed.

### Recommended Pattern

```php
$results = SalesforceBatch::new()
    ->add('leads', Lead::where('Status', 'Open'))
    ->add('contacts', Contact::all())
    ->run();

if (! $results->allSuccessful()) {
    foreach ($results->failures() as $name) {
        Log::warning("Batch query '{$name}' failed", $results->error($name));
    }
}

// Safe to use successful results
if ($results->successful('leads')) {
    $leads = $results->get('leads');
}
```

## Configuration

The batch size is configured in `config/eloquent-salesforce-objects.php`:

```php
// Maximum queries per batch request (Salesforce max is 25)
'batch_size' => env('SALESFORCE_BATCH_SIZE', 25),
```

If you add more than 25 queries, they're automatically chunked into multiple API calls.

## Examples

### Dashboard Data Loading

```php
use Daikazu\EloquentSalesforceObjects\Database\SalesforceBatch;

// Load all dashboard data in one API call instead of 4
$results = SalesforceBatch::new()
    ->add('recent_leads', Lead::where('CreatedDate', '>', now()->subDays(7))->limit(10))
    ->add('open_opps', Opportunity::where('StageName', '!=', 'Closed Won')->orderBy('CloseDate'))
    ->add('top_accounts', Account::orderBy('AnnualRevenue', 'desc')->limit(5))
    ->add('stats', "SELECT StageName, COUNT(Id) total FROM Opportunity GROUP BY StageName")
    ->run();

return view('dashboard', [
    'recentLeads'  => $results->get('recent_leads'),
    'openOpps'     => $results->get('open_opps'),
    'topAccounts'  => $results->get('top_accounts'),
    'stageStats'   => $results->get('stats'),
]);
```

### Fetching Related Data

```php
// Get an account and all its related data in one call
$results = SalesforceBatch::new()
    ->add('account', Account::where('Id', $accountId))
    ->add('contacts', Contact::where('AccountId', $accountId))
    ->add('opportunities', Opportunity::where('AccountId', $accountId))
    ->add('cases', "SELECT Id, Subject, Status FROM Case WHERE AccountId = '{$accountId}'")
    ->run();

$account = $results->get('account')?->first();
$contacts = $results->get('contacts');
$opportunities = $results->get('opportunities');
$cases = $results->get('cases');
```

### Conditional Processing

```php
$batch = SalesforceBatch::new();

// Dynamically add queries based on what the user needs
if ($request->has('show_leads')) {
    $batch->add('leads', Lead::where('OwnerId', $userId));
}
if ($request->has('show_contacts')) {
    $batch->add('contacts', Contact::where('OwnerId', $userId));
}

$results = $batch->run();
```
