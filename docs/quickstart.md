# Quickstart Guide

Get up and running with Eloquent Salesforce Objects in 5 minutes!

## Prerequisites

Make sure you've completed the [installation](installation.md) and have your Salesforce credentials configured.

## Step 1: Create Your First Model

Create a model for the Salesforce Account object:

```php
<?php

namespace App\Models;

use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;

class Account extends SalesforceModel
{
    // The Salesforce object API name
    protected $table = 'Account';

    // Fields that can be mass-assigned
    protected $fillable = [
        'Name',
        'Industry',
        'AnnualRevenue',
        'Phone',
        'Website',
    ];
}
```

Save this as `app/Models/Account.php`.

## Step 2: Query Data

Now you can query Salesforce data using Eloquent syntax:

```php
use App\Models\Account;

// Get all accounts
$accounts = Account::all();

// Query with conditions
$techAccounts = Account::where('Industry', 'Technology')
    ->orderBy('Name')
    ->limit(10)
    ->get();

// Find specific account by ID
$account = Account::find('001xx000003DGb2AAG');

// Get first matching account
$account = Account::where('Name', 'Acme Corp')->first();
```

## Step 3: Create a Record

Create a new Salesforce record:

```php
use App\Models\Account;

$account = Account::create([
    'Name' => 'Acme Corporation',
    'Industry' => 'Technology',
    'AnnualRevenue' => 5000000,
    'Phone' => '555-1234',
    'Website' => 'https://acme.com',
]);

// The ID is automatically set
echo "Created account: " . $account->Id;
```

## Step 4: Update a Record

Update an existing record:

```php
use App\Models\Account;

// Find the account
$account = Account::find('001xx000003DGb2AAG');

// Update fields
$account->Phone = '555-5678';
$account->Industry = 'Manufacturing';

// Save changes
$account->save();
```

Or update multiple fields at once:

```php
$account->update([
    'Phone' => '555-5678',
    'Industry' => 'Manufacturing',
]);
```

## Step 5: Delete a Record

Delete a record from Salesforce:

```php
use App\Models\Account;

$account = Account::find('001xx000003DGb2AAG');
$account->delete();
```

## Complete Example: CRUD Operations

Here's a complete example showing all CRUD operations:

```php
use App\Models\Account;

// CREATE
$account = Account::create([
    'Name' => 'Test Company',
    'Industry' => 'Technology',
]);
echo "Created: {$account->Id}\n";

// READ
$account = Account::find($account->Id);
echo "Name: {$account->Name}\n";
echo "Industry: {$account->Industry}\n";

// UPDATE
$account->Industry = 'Manufacturing';
$account->save();
echo "Updated industry to: {$account->Industry}\n";

// DELETE
$account->delete();
echo "Deleted account\n";
```

## Example: Building a Controller

Create a controller to manage Salesforce accounts:

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
        return view('accounts.index', compact('accounts'));
    }

    public function show($id)
    {
        $account = Account::find($id);
        return view('accounts.show', compact('account'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'Name' => 'required|string|max:255',
            'Industry' => 'nullable|string',
            'Phone' => 'nullable|string',
        ]);

        $account = Account::create($validated);

        return redirect()
            ->route('accounts.show', $account->Id)
            ->with('success', 'Account created successfully!');
    }

    public function update(Request $request, $id)
    {
        $account = Account::find($id);

        $validated = $request->validate([
            'Name' => 'required|string|max:255',
            'Industry' => 'nullable|string',
            'Phone' => 'nullable|string',
        ]);

        $account->update($validated);

        return redirect()
            ->route('accounts.show', $id)
            ->with('success', 'Account updated successfully!');
    }

    public function destroy($id)
    {
        $account = Account::find($id);
        $account->delete();

        return redirect()
            ->route('accounts.index')
            ->with('success', 'Account deleted successfully!');
    }
}
```

## Example: Working with Relationships

Create related models:

```php
<?php

namespace App\Models;

use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;

class Account extends SalesforceModel
{
    protected $table = 'Account';

    protected $fillable = ['Name', 'Industry'];

    public function contacts()
    {
        return $this->hasMany(Contact::class, 'AccountId');
    }
}

class Contact extends SalesforceModel
{
    protected $table = 'Contact';

    protected $fillable = ['FirstName', 'LastName', 'Email', 'AccountId'];

    public function account()
    {
        return $this->belongsTo(Account::class, 'AccountId');
    }
}
```

Use the relationships:

```php
use App\Models\Account;

// Eager load contacts
$accounts = Account::with('contacts')->get();

foreach ($accounts as $account) {
    echo "{$account->Name} has {$account->contacts->count()} contacts\n";

    foreach ($account->contacts as $contact) {
        echo "  - {$contact->FirstName} {$contact->LastName}\n";
    }
}

// Access parent account from contact
$contact = Contact::with('account')->first();
echo "Contact: {$contact->FirstName} works at {$contact->account->Name}\n";
```

## Common Patterns

### Search Accounts by Name

```php
$query = request('search');

$accounts = Account::where('Name', 'LIKE', "%{$query}%")
    ->orderBy('Name')
    ->limit(50)
    ->get();
```

### Get Recent Opportunities

```php
$recentOpportunities = Opportunity::where('Stage', 'Closed Won')
    ->whereDate('CloseDate', '>=', now()->subDays(30))
    ->orderBy('CloseDate', 'desc')
    ->get();
```

### Count Accounts by Industry

```php
$industries = ['Technology', 'Manufacturing', 'Healthcare'];

foreach ($industries as $industry) {
    $count = Account::where('Industry', $industry)->count();
    echo "{$industry}: {$count} accounts\n";
}
```

### Bulk Create Contacts

```php
$contactsData = [
    ['FirstName' => 'John', 'LastName' => 'Doe', 'Email' => 'john@example.com'],
    ['FirstName' => 'Jane', 'LastName' => 'Smith', 'Email' => 'jane@example.com'],
    ['FirstName' => 'Bob', 'LastName' => 'Jones', 'Email' => 'bob@example.com'],
];

$contacts = Contact::insert($contactsData);
echo "Created " . count($contacts) . " contacts\n";
```

## Performance Tips

### Use Caching for Repeated Queries

Queries are automatically cached by default:

```php
// First call - hits Salesforce API
$accounts = Account::where('Industry', 'Technology')->get();

// Second call - returns from cache (instant!)
$accounts = Account::where('Industry', 'Technology')->get();
```

### Use Bulk Operations for Multiple Records

Instead of saving one at a time:

```php
// Slow - 100 API calls
foreach ($data as $item) {
    Account::create($item);
}

// Fast - 1 API call
Account::insert($data);
```

### Select Only Needed Fields

```php
// Gets all fields (slower)
$accounts = Account::all();

// Gets only specific fields (faster)
$accounts = Account::select(['Id', 'Name', 'Industry'])->get();
```

## Next Steps

Now that you have the basics, explore more advanced features:

- **[Models](models.md)** - Learn about model configuration and advanced features
- **[Querying](querying.md)** - Master the query builder
- **[Relationships](relationships.md)** - Define complex object relationships
- **[Caching](caching.md)** - Optimize performance with intelligent caching
- **[Bulk Operations](bulk-operations.md)** - Work with large datasets efficiently

## Getting Help

- **Documentation**: Browse the full [documentation](README.md)
- **Issues**: Report bugs on [GitHub Issues](https://github.com/daikazu/eloquent-salesforce-objects/issues)
- **Examples**: Check out the `examples/` directory in the package
