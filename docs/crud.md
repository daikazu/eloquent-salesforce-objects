# CRUD Operations

Learn how to create, read, update, and delete Salesforce records.

## Table of Contents

- [Creating Records](#creating-records)
- [Reading Records](#reading-records)
- [Updating Records](#updating-records)
- [Deleting Records](#deleting-records)
- [Error Handling](#error-handling)
- [Mass Assignment](#mass-assignment)
- [Validation](#validation)
- [Best Practices](#best-practices)

## Creating Records

### Using create()

Create a record with mass assignment:

```php
use App\Models\Account;

$account = Account::create([
    'Name' => 'Acme Corporation',
    'Industry' => 'Technology',
    'AnnualRevenue' => 5000000,
    'Phone' => '555-1234',
    'Website' => 'https://acme.com',
]);

// Access the created record
echo "Created account with ID: " . $account->Id;
echo "Name: " . $account->Name;
```

### Using new and save()

Create a record manually:

```php
$account = new Account();
$account->Name = 'Acme Corporation';
$account->Industry = 'Technology';
$account->AnnualRevenue = 5000000;
$account->save();

echo "Created account: " . $account->Id;
```

### Setting Defaults

Set default values before creating:

```php
$contact = new Contact([
    'FirstName' => 'John',
    'LastName' => 'Doe',
]);

// Set default values
$contact->LeadSource = $contact->LeadSource ?? 'Website';
$contact->Status__c = 'New';

$contact->save();
```

### Creating with Relationships

Create a record and associate it with a parent:

```php
// Create contact linked to an account
$contact = Contact::create([
    'FirstName' => 'Jane',
    'LastName' => 'Smith',
    'Email' => 'jane@example.com',
    'AccountId' => $account->Id, // Link to account
]);
```

## Reading Records

### Find by ID

```php
// Find single record
$account = Account::find('001xx000003DGb2AAG');

if ($account) {
    echo $account->Name;
} else {
    echo "Account not found";
}
```

### Find or Fail

Throw an exception if not found:

```php
try {
    $account = Account::findOrFail('001xx000003DGb2AAG');
    echo $account->Name;
} catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
    return response()->json(['error' => 'Account not found'], 404);
}
```

### Get First Record

```php
// Get first record
$account = Account::first();

// Get first matching condition
$account = Account::where('Industry', 'Technology')->first();

// First or fail
$account = Account::where('Industry', 'Technology')->firstOrFail();
```

### Get All Records

```php
// Get all records
$accounts = Account::all();

// Get with conditions
$accounts = Account::where('Industry', 'Technology')->get();
```

### Checking if Record Exists

```php
// Check if record exists
if (Account::where('Name', 'Acme')->exists()) {
    echo "Account exists";
}

// Check if record doesn't exist
if (Account::where('Name', 'NonExistent')->doesntExist()) {
    echo "Account does not exist";
}
```

## Updating Records

### Update Single Record

```php
// Find and update
$account = Account::find($id);
$account->Industry = 'Manufacturing';
$account->Phone = '555-9999';
$account->save();
```

### Mass Update

Update multiple fields at once:

```php
$account = Account::find($id);
$account->update([
    'Industry' => 'Manufacturing',
    'Phone' => '555-9999',
    'Website' => 'https://newsite.com',
]);
```

### Update or Create

Update if exists, create if not:

```php
$account = Account::updateOrCreate(
    ['Name' => 'Acme Corporation'], // Search criteria
    [
        'Industry' => 'Technology',
        'Phone' => '555-1234',
    ] // Values to update/create
);
```

### Conditional Updates

Only update if value changed:

```php
$account = Account::find($id);

if ($account->Industry !== 'Technology') {
    $account->Industry = 'Technology';
    $account->save();
}

// Or check if dirty
$account->Industry = 'Technology';

if ($account->isDirty()) {
    $account->save();
}
```

### Checking What Changed

```php
$account = Account::find($id);
$account->Industry = 'Technology';
$account->Phone = '555-9999';

// Check if specific field changed
if ($account->isDirty('Industry')) {
    echo "Industry changed";
}

// Get all changed fields
$changes = $account->getDirty();
// ['Industry' => 'Technology', 'Phone' => '555-9999']

// Save and get what was changed
$account->save();
$changes = $account->getChanges();
```

### Increment/Decrement

Note: Salesforce doesn't support direct increment/decrement. You must read, modify, and save:

```php
$opportunity = Opportunity::find($id);
$opportunity->Amount = $opportunity->Amount + 1000;
$opportunity->save();
```

## Deleting Records

### Delete Single Record

```php
// Find and delete
$account = Account::find($id);
$account->delete();
```

### Delete by ID

```php
// Delete without retrieving
Account::destroy($id);

// Delete multiple
Account::destroy([$id1, $id2, $id3]);
```

### Soft Deletes

Most Salesforce objects support soft deletes:

```php
// Soft delete (sets IsDeleted = true)
$account = Account::find($id);
$account->delete();

// Query only non-deleted
$accounts = Account::all();

// Include soft-deleted records
$accounts = Account::withTrashed()->get();

// Only soft-deleted records
$accounts = Account::onlyTrashed()->get();

// Restore soft-deleted record
$account = Account::withTrashed()->find($id);
$account->restore();
```

### Force Delete

Permanently delete (not supported by most Salesforce objects):

```php
$account->forceDelete();
```

### Delete with Conditions

```php
// Delete all accounts matching criteria
Account::where('Industry', 'Obsolete')->delete();
```

## Error Handling

### Handling Create Errors

```php
try {
    $account = Account::create([
        'Name' => 'Acme Corp',
        'Industry' => 'Technology',
    ]);
} catch (\Exception $e) {
    // Handle Salesforce errors
    logger()->error('Failed to create account: ' . $e->getMessage());

    return response()->json([
        'error' => 'Failed to create account',
        'message' => $e->getMessage(),
    ], 500);
}
```

### Handling Update Errors

```php
$account = Account::find($id);

if (!$account) {
    return response()->json(['error' => 'Account not found'], 404);
}

try {
    $account->update($request->validated());
} catch (\Exception $e) {
    return response()->json([
        'error' => 'Failed to update account',
        'message' => $e->getMessage(),
    ], 500);
}
```

### Configuration-Based Error Handling

Configure exception handling in `config/eloquent-salesforce-objects.php`:

```php
// Throw exceptions (good for development)
'throw_exceptions' => env('SALESFORCE_THROW_EXCEPTIONS', true),

// Or log and return false (good for production)
'throw_exceptions' => false,
```

When `throw_exceptions` is false:

```php
$account = Account::create([
    'Name' => 'Test',
    'InvalidField' => 'value', // Invalid field
]);

if ($account === false) {
    // Creation failed, check logs
    echo "Failed to create account";
}
```

## Mass Assignment

### Fillable Properties

Define which fields can be mass-assigned:

```php
class Account extends SalesforceModel
{
    protected $fillable = [
        'Name',
        'Industry',
        'Phone',
        'Website',
    ];
}

// These work
Account::create([
    'Name' => 'Acme',
    'Industry' => 'Tech',
]);

// This would be ignored
Account::create([
    'OwnerId' => 'some-id', // Not in fillable
]);
```

### Guarded Properties

Define which fields cannot be mass-assigned:

```php
class Account extends SalesforceModel
{
    protected $guarded = [
        'OwnerId',
        'CreatedById',
        'LastModifiedById',
    ];
}
```

### Force Fill

Bypass mass assignment protection:

```php
$account = new Account();
$account->forceFill([
    'Name' => 'Acme',
    'OwnerId' => 'some-id', // Can set guarded field
])->save();
```

## Validation

### Laravel Validation

Validate before creating/updating:

```php
use Illuminate\Support\Facades\Validator;

$validator = Validator::make($request->all(), [
    'Name' => 'required|string|max:255',
    'Industry' => 'nullable|string',
    'AnnualRevenue' => 'nullable|numeric|min:0',
    'Email' => 'nullable|email',
    'Website' => 'nullable|url',
]);

if ($validator->fails()) {
    return response()->json([
        'errors' => $validator->errors()
    ], 422);
}

$account = Account::create($validator->validated());
```

### Form Request Validation

Create a form request:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccountRequest extends FormRequest
{
    public function rules()
    {
        return [
            'Name' => 'required|string|max:255',
            'Industry' => 'nullable|string',
            'Phone' => 'nullable|string|max:20',
            'Website' => 'nullable|url',
            'AnnualRevenue' => 'nullable|numeric|min:0',
        ];
    }
}
```

Use in controller:

```php
public function store(StoreAccountRequest $request)
{
    $account = Account::create($request->validated());

    return response()->json($account, 201);
}
```

### Model Validation

Validate in model events:

```php
class Account extends SalesforceModel
{
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($account) {
            if (empty($account->Name)) {
                throw new \Exception('Name is required');
            }

            if ($account->AnnualRevenue < 0) {
                throw new \Exception('Annual revenue must be positive');
            }
        });
    }
}
```

## Best Practices

### 1. Always Validate Input

```php
// Good
$validated = $request->validate([
    'Name' => 'required|string|max:255',
]);
Account::create($validated);

// Bad
Account::create($request->all()); // No validation
```

### 2. Use Transactions for Related Operations

```php
DB::transaction(function () use ($accountData, $contactsData) {
    $account = Account::create($accountData);

    foreach ($contactsData as $contactData) {
        Contact::create([
            'AccountId' => $account->Id,
            ...$contactData,
        ]);
    }
});
```

### 3. Check for Existence Before Update

```php
// Good
$account = Account::find($id);
if (!$account) {
    return response()->json(['error' => 'Not found'], 404);
}
$account->update($data);

// Bad
Account::find($id)->update($data); // Could fail if null
```

### 4. Use Mass Assignment Protection

```php
// Define fillable fields
protected $fillable = ['Name', 'Industry', 'Phone'];

// Never use
protected $guarded = []; // Allows all fields
```

### 5. Handle Errors Gracefully

```php
try {
    $account = Account::create($data);
    return response()->json($account, 201);
} catch (\Exception $e) {
    logger()->error('Account creation failed', [
        'data' => $data,
        'error' => $e->getMessage(),
    ]);

    return response()->json([
        'error' => 'Failed to create account',
    ], 500);
}
```

### 6. Use Model Events for Business Logic

```php
static::creating(function ($account) {
    // Set defaults
    $account->Status = $account->Status ?? 'New';

    // Generate custom ID
    $account->CustomId__c = Str::uuid();
});

static::updated(function ($account) {
    // Log changes
    if ($account->isDirty('Industry')) {
        Log::info("Account {$account->Id} industry changed to {$account->Industry}");
    }
});
```

### 7. Optimize Bulk Operations

```php
// Slow - N+1 API calls
foreach ($data as $item) {
    Account::create($item);
}

// Fast - Single bulk API call
Account::insert($data);
```

## Complete CRUD Example

Here's a complete controller with all CRUD operations:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index()
    {
        $accounts = Account::orderBy('Name')->paginate(20);
        return response()->json($accounts);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'Name' => 'required|string|max:255',
            'Industry' => 'nullable|string',
            'Phone' => 'nullable|string',
            'Website' => 'nullable|url',
            'AnnualRevenue' => 'nullable|numeric|min:0',
        ]);

        try {
            $account = Account::create($validated);
            return response()->json($account, 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create account',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $account = Account::findOrFail($id);
            return response()->json($account);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Account not found'], 404);
        }
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'Name' => 'sometimes|required|string|max:255',
            'Industry' => 'nullable|string',
            'Phone' => 'nullable|string',
            'Website' => 'nullable|url',
            'AnnualRevenue' => 'nullable|numeric|min:0',
        ]);

        try {
            $account = Account::findOrFail($id);
            $account->update($validated);
            return response()->json($account);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Account not found'], 404);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update account',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $account = Account::findOrFail($id);
            $account->delete();
            return response()->json(['message' => 'Account deleted successfully']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Account not found'], 404);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete account',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
```

## Next Steps

- **[Bulk Operations](bulk-operations.md)** - Efficiently work with multiple records
- **[Relationships](relationships.md)** - Work with related records
- **[Validation](https://laravel.com/docs/validation)** - Laravel validation documentation
