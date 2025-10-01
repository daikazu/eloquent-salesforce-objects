# Bulk Operations

Learn how to efficiently work with multiple Salesforce records using bulk insert, update, and delete operations.

## Table of Contents

- [Introduction](#introduction)
- [Bulk Insert](#bulk-insert)
- [Bulk Update](#bulk-update)
- [Bulk Delete](#bulk-delete)
- [Advanced Features](#advanced-features)
- [Error Handling](#error-handling)
- [Best Practices](#best-practices)
- [Complete Examples](#complete-examples)
- [Configuration](#configuration)

## Introduction

Salesforce bulk operations allow you to insert, update, or delete multiple records in a single API call, dramatically improving performance and reducing API usage.

### Benefits

- **Faster**: One API call instead of N calls
- **Efficient**: Reduces API limit consumption (15x faster, 99.5% fewer API calls for 200 records)
- **Scalable**: Handle hundreds of records at once
- **Transactional**: All-or-none option available

### Limits

- Maximum **200 records** per bulk operation
- Operations are automatically chunked if you exceed this limit
- Chunking is handled transparently by the package

## Bulk Insert

Insert multiple records in a single API call.

### Basic Usage

```php
use App\Models\Contact;

$contactsData = [
    ['FirstName' => 'John', 'LastName' => 'Doe', 'Email' => 'john@example.com'],
    ['FirstName' => 'Jane', 'LastName' => 'Smith', 'Email' => 'jane@example.com'],
    ['FirstName' => 'Bob', 'LastName' => 'Jones', 'Email' => 'bob@example.com'],
];

// Insert all at once
$contacts = Contact::insert($contactsData);

// Returns collection of created models with IDs
foreach ($contacts as $contact) {
    echo "Created contact: {$contact->Id}\n";
}
```

### With Related Records

```php
$account = Account::find($accountId);

$contactsData = [
    [
        'FirstName' => 'John',
        'LastName' => 'Doe',
        'Email' => 'john@example.com',
        'AccountId' => $account->Id,
    ],
    [
        'FirstName' => 'Jane',
        'LastName' => 'Smith',
        'Email' => 'jane@example.com',
        'AccountId' => $account->Id,
    ],
];

$contacts = Contact::insert($contactsData);
```

## Bulk Update

Update multiple records efficiently.

### Basic Usage

```php
use App\Models\Account;

$updates = [
    ['Id' => '001xx000001', 'Phone' => '555-0001', 'Industry' => 'Technology'],
    ['Id' => '001xx000002', 'Phone' => '555-0002', 'Industry' => 'Manufacturing'],
    ['Id' => '001xx000003', 'Phone' => '555-0003', 'Industry' => 'Healthcare'],
];

$accounts = Account::bulkUpdate($updates);
```

### Update from Query Results

```php
// Get accounts to update
$accounts = Account::where('Industry', 'Technology')
    ->where('AnnualRevenue', '>', 1000000)
    ->get();

// Build updates with conditional logic
$updates = $accounts->map(function ($account) {
    return [
        'Id' => $account->Id,
        'Status__c' => 'Premium',
        'Rating' => $account->AnnualRevenue > 5000000 ? 'Hot' : 'Warm',
    ];
})->toArray();

Account::bulkUpdate($updates);
```

## Bulk Delete

Delete multiple records at once.

### Delete by Query

```php
// Delete all inactive accounts
$deletedCount = Account::where('IsActive__c', false)->delete();

echo "Deleted {$deletedCount} accounts";
```

### Delete by IDs

```php
$idsToDelete = ['001xx000001', '001xx000002', '001xx000003'];

Account::destroy($idsToDelete);
```

### Delete with Complex Query

```php
// Delete old inactive records (automatically chunked)
$count = Account::where('CreatedDate', '<', now()->subYears(5))
    ->where('IsActive__c', false)
    ->delete();

echo "Deleted {$count} old inactive accounts";
```

## Advanced Features

### All-or-None Transactions

By default, bulk operations continue even if some records fail. Use `allOrNone` to roll back everything if any record fails:

```php
try {
    $contacts = Contact::insert($contactsData, allOrNone: true);
    echo "All contacts created successfully";
} catch (\Exception $e) {
    echo "Insert failed, all records rolled back: " . $e->getMessage();
}
```

> **How It Works:** The `allOrNone` parameter is passed directly to [Salesforce's Composite SObject Collections API](https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/resources_composite_allornone.htm). Salesforce handles the rollback using server-side database transactions - if any record fails, Salesforce automatically rolls back all changes before returning an error response. This provides true ACID transaction guarantees at the API level. The rollback happens per chunk (maximum 200 records per chunk).

**All operations support `allOrNone`:**

```php
// Insert with rollback
Contact::insert($data, allOrNone: true);

// Delete with rollback
Account::query()->delete(allOrNone: true);

// Update with rollback (via adapter)
Account::bulkUpdate($updates, allOrNone: true);
```

### Automatic Chunking

The package automatically chunks large datasets into batches of 200 records:

```php
// This array has 1,000 records
$largeDataset = [];
for ($i = 0; $i < 1000; $i++) {
    $largeDataset[] = [
        'FirstName' => "User{$i}",
        'LastName' => 'Test',
        'Email' => "user{$i}@example.com",
    ];
}

// Automatically chunked into 5 API calls (200 records each)
$contacts = Contact::insert($largeDataset);
echo "Created " . count($contacts) . " contacts";
```

**How chunking works:**
- 500 records → 3 chunks (200, 200, 100)
- 1,000 records → 5 chunks (200 × 5)
- Happens automatically, no configuration needed
- Each chunk is a separate API call

### Progress Tracking for Large Operations

For very large datasets, track progress across chunks:

```php
$allData = [...]; // 10,000 records
$chunks = array_chunk($allData, 200);
$totalCreated = 0;

foreach ($chunks as $index => $chunk) {
    $results = Contact::insert($chunk);
    $totalCreated += count($results);

    $progress = (($index + 1) / count($chunks)) * 100;
    echo "Progress: " . round($progress, 2) . "%\n";

    // Update database or cache with progress
    Cache::put('import_progress', $progress, 3600);
}
```

**With rate limiting:**

```php
collect($data)->chunk(200)->each(function ($chunk, $index) {
    Contact::insert($chunk->toArray());

    // Small delay to avoid rate limits
    usleep(100000); // 0.1 second

    Log::info("Processed chunk " . ($index + 1));
});
```

## Error Handling

### Handling Partial Failures

By default, successful records are created even if some fail:

```php
$data = [
    ['FirstName' => 'John', 'LastName' => 'Doe'],     // ✓ Valid
    ['FirstName' => 'Jane'],                          // ✗ Invalid - missing LastName
    ['FirstName' => 'Bob', 'LastName' => 'Jones'],   // ✓ Valid
];

$results = Contact::insert($data);

// 2 records created successfully, 1 failed
echo "Created " . count($results) . " out of " . count($data) . " contacts";
```

### Catching Exceptions

```php
try {
    $contacts = Contact::insert($data);
} catch (\Daikazu\EloquentSalesforceObjects\Exceptions\SalesforceException $e) {
    logger()->error('Bulk insert failed', [
        'message' => $e->getMessage(),
        'data_count' => count($data),
    ]);

    // Retry with smaller batch
    $contacts = Contact::insert(array_slice($data, 0, 50));
}
```

### Checking for Partial Failures

```php
try {
    $results = Contact::insert($data);

    // Check for partial failures
    if (count($results) < count($data)) {
        $failedCount = count($data) - count($results);
        logger()->warning("Bulk insert partial failure", [
            'total' => count($data),
            'successful' => count($results),
            'failed' => $failedCount,
        ]);
    }
} catch (\Exception $e) {
    logger()->error('Bulk insert completely failed', [
        'error' => $e->getMessage(),
        'record_count' => count($data),
    ]);

    throw $e;
}
```

### Configuration Options

Control exception behavior in `config/eloquent-salesforce-objects.php`:

```php
'throw_exceptions' => true,  // Throw exceptions on failure
'log_level' => 'error',      // Log level for errors
```

Set to `false` to log errors instead of throwing:

```php
'throw_exceptions' => false, // Log instead of throw
```

## Best Practices

### 1. Always Use Bulk Operations for Multiple Records

```php
// ✓ Good - Single API call
$contacts = Contact::insert($data);

// ✗ Bad - N API calls (slow, uses API limits)
foreach ($data as $item) {
    Contact::create($item);
}
```

**Performance comparison (200 records):**
- Single operations: ~30 seconds, 200 API calls
- Bulk operations: ~2 seconds, 1 API call
- **Result: 15x faster, 99.5% fewer API calls**

### 2. Validate Data Before Bulk Operations

```php
use Illuminate\Support\Facades\Validator;

$validator = Validator::make(['contacts' => $data], [
    'contacts.*.FirstName' => 'required',
    'contacts.*.LastName' => 'required',
    'contacts.*.Email' => 'required|email',
]);

if ($validator->fails()) {
    return response()->json($validator->errors(), 422);
}

// All valid, proceed
$contacts = Contact::insert($data);
```

### 3. Use All-or-None for Critical Data

Use `allOrNone: true` when data consistency is critical:

```php
// Financial records - all or nothing
$transactions = Transaction::insert($transactionData, allOrNone: true);

// Regular contacts - allow partial success
$contacts = Contact::insert($contactData); // allOrNone: false (default)
```

### 4. Monitor Progress for Large Imports

- Track progress for datasets over 1,000 records
- Store progress in cache or database
- Provide user feedback via queues or websockets
- Log chunk completion for debugging

### 5. Handle Errors Gracefully

- Always check for partial failures
- Log errors with context (record count, operation type)
- Provide meaningful error messages to users
- Consider retry logic for transient failures

### 6. Be Aware of Chunking Limits

- Salesforce enforces 200 records per bulk operation
- The package chunks automatically but each chunk is a separate transaction
- If using `allOrNone: true`, only that chunk rolls back (not all chunks)
- For true multi-chunk transactions, process all data in one chunk (≤ 200 records)

## Complete Examples

### CSV Import with Progress

```php
use App\Models\Contact;
use Illuminate\Support\Facades\Storage;

public function importContacts($filename)
{
    $csv = Storage::get($filename);
    $rows = array_map('str_getcsv', explode("\n", $csv));
    $header = array_shift($rows);

    // Parse CSV data
    $data = [];
    foreach ($rows as $row) {
        if (count($row) < count($header)) continue;

        $data[] = [
            'FirstName' => $row[0],
            'LastName' => $row[1],
            'Email' => $row[2],
            'Phone' => $row[3],
        ];
    }

    // Bulk insert with progress tracking
    $totalCreated = 0;
    $chunks = array_chunk($data, 200);

    foreach ($chunks as $index => $chunk) {
        $results = Contact::insert($chunk);
        $totalCreated += count($results);

        // Update progress
        $progress = (($index + 1) / count($chunks)) * 100;
        Cache::put("import_progress_{$filename}", $progress, 3600);
    }

    return response()->json([
        'message' => "Successfully imported {$totalCreated} contacts",
        'total_records' => count($data),
        'successful' => $totalCreated,
        'failed' => count($data) - $totalCreated,
    ]);
}
```

### Bulk Update with Validation

```php
public function bulkUpdateAccounts(Request $request)
{
    $updates = $request->validate([
        'accounts' => 'required|array|min:1',
        'accounts.*.id' => 'required|string',
        'accounts.*.industry' => 'nullable|string',
        'accounts.*.phone' => 'nullable|string',
    ]);

    $updateData = collect($updates['accounts'])->map(function ($account) {
        return [
            'Id' => $account['id'],
            'Industry' => $account['industry'] ?? null,
            'Phone' => $account['phone'] ?? null,
        ];
    })->toArray();

    try {
        $results = Account::bulkUpdate($updateData);

        return response()->json([
            'message' => 'Accounts updated successfully',
            'updated_count' => count($results),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Bulk update failed',
            'message' => $e->getMessage(),
        ], 500);
    }
}
```

### Cleanup Old Records

```php
public function cleanupInactiveRecords()
{
    $cutoffDate = now()->subYears(2);

    // Delete inactive accounts
    $deletedAccounts = Account::where('IsActive__c', false)
        ->whereDate('LastActivityDate', '<', $cutoffDate)
        ->delete();

    // Delete orphaned contacts (no account)
    $deletedContacts = Contact::whereNull('AccountId')
        ->whereDate('LastActivityDate', '<', $cutoffDate)
        ->delete();

    return response()->json([
        'message' => 'Cleanup completed',
        'deleted_accounts' => $deletedAccounts,
        'deleted_contacts' => $deletedContacts,
        'total_deleted' => $deletedAccounts + $deletedContacts,
    ]);
}
```

## Configuration

Configure bulk operation size limits in `config/eloquent-salesforce-objects.php`:

```php
'bulk_operation_size' => env('SALESFORCE_BULK_OPERATION_SIZE', 200),
```

Or in `.env`:

```env
SALESFORCE_BULK_OPERATION_SIZE=200
```

**Important:** Salesforce's maximum is 200 records per bulk operation. Don't increase this value beyond 200.

## Next Steps

- **[CRUD Operations](crud.md)** - Single record operations
- **[Querying](querying.md)** - Retrieving records
- **[Configuration](configuration.md)** - Package configuration options
