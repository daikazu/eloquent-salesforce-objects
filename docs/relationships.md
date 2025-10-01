# Relationships

Learn how to define and work with relationships between Salesforce objects.

## Table of Contents

- [Introduction](#introduction)
- [One-to-Many (hasMany)](#one-to-many-hasmany)
- [Many-to-One (belongsTo)](#many-to-one-belongsto)
- [One-to-One (hasOne)](#one-to-one-hasone)
- [Eager Loading](#eager-loading)
- [Lazy Loading](#lazy-loading)
- [Querying Relationships](#querying-relationships)
- [Relationship Methods](#relationship-methods)
- [Best Practices](#best-practices)

## Introduction

Relationships in Salesforce are defined using lookup and master-detail fields. This package provides Eloquent-style relationship methods to work with these relationships.

### Relationship Types Supported

- **hasMany**: One-to-many (Account has many Contacts)
- **belongsTo**: Many-to-one (Contact belongs to Account)
- **hasOne**: One-to-one (Account has one Primary Contact)

## One-to-Many (hasMany)

Define a one-to-many relationship where the parent has multiple children.

### Defining hasMany

```php
<?php

namespace App\Models;

use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;

class Account extends SalesforceModel
{
    protected $table = 'Account';

    public function contacts()
    {
        return $this->hasMany(Contact::class, 'AccountId');
    }

    public function opportunities()
    {
        return $this->hasMany(Opportunity::class, 'AccountId');
    }

    public function cases()
    {
        return $this->hasMany(Case::class, 'AccountId');
    }
}
```

### Using hasMany

```php
use App\Models\Account;

// Get account with contacts
$account = Account::find($id);
$contacts = $account->contacts;

// Loop through contacts
foreach ($contacts as $contact) {
    echo "{$contact->FirstName} {$contact->LastName}\n";
}

// Count related records
$contactCount = $account->contacts->count();

// Check if has contacts
if ($account->contacts->isEmpty()) {
    echo "No contacts";
}
```

### Custom Foreign Key

If the foreign key doesn't follow conventions:

```php
public function contacts()
{
    // Specify custom foreign key
    return $this->hasMany(Contact::class, 'Custom_Account_Id__c');
}
```

## Many-to-One (belongsTo)

Define a many-to-one relationship where the child belongs to a parent.

### Defining belongsTo

```php
<?php

namespace App\Models;

use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;

class Contact extends SalesforceModel
{
    protected $table = 'Contact';

    public function account()
    {
        return $this->belongsTo(Account::class, 'AccountId');
    }
}

class Opportunity extends SalesforceModel
{
    protected $table = 'Opportunity';

    public function account()
    {
        return $this->belongsTo(Account::class, 'AccountId');
    }
}
```

### Using belongsTo

```php
use App\Models\Contact;

// Get contact with account
$contact = Contact::find($id);
$account = $contact->account;

if ($account) {
    echo "Contact works at: {$account->Name}";
}

// Access parent properties
echo $contact->account->Industry;
echo $contact->account->Phone;
```

### Null Safety

```php
// Check if relationship exists
if ($contact->account) {
    echo $contact->account->Name;
} else {
    echo "No account associated";
}

// Using optional helper
echo optional($contact->account)->Name ?? 'No account';
```

## One-to-One (hasOne)

Define a one-to-one relationship where the parent has exactly one child.

### Defining hasOne

```php
class Account extends SalesforceModel
{
    public function primaryContact()
    {
        return $this->hasOne(Contact::class, 'AccountId')
            ->where('IsPrimary__c', true);
    }

    public function billingAddress()
    {
        return $this->hasOne(Address::class, 'AccountId')
            ->where('Type', 'Billing');
    }
}
```

### Using hasOne

```php
$account = Account::find($id);

// Get single related record
$primaryContact = $account->primaryContact;

if ($primaryContact) {
    echo "Primary contact: {$primaryContact->FirstName} {$primaryContact->LastName}";
}
```

## Eager Loading

Load relationships upfront to avoid N+1 query problems.

### Basic Eager Loading

```php
// Load single relationship
$accounts = Account::with('contacts')->get();

foreach ($accounts as $account) {
    // No additional query - contacts already loaded
    foreach ($account->contacts as $contact) {
        echo $contact->FirstName;
    }
}
```

### Multiple Relationships

```php
// Load multiple relationships
$accounts = Account::with(['contacts', 'opportunities', 'cases'])->get();

foreach ($accounts as $account) {
    echo "Account: {$account->Name}\n";
    echo "Contacts: {$account->contacts->count()}\n";
    echo "Opportunities: {$account->opportunities->count()}\n";
    echo "Cases: {$account->cases->count()}\n";
}
```

### Nested Eager Loading

```php
// Load nested relationships
$opportunities = Opportunity::with('account.contacts')->get();

foreach ($opportunities as $opportunity) {
    echo "Opportunity: {$opportunity->Name}\n";
    echo "Account: {$opportunity->account->Name}\n";

    foreach ($opportunity->account->contacts as $contact) {
        echo "  Contact: {$contact->FirstName} {$contact->LastName}\n";
    }
}
```

### Conditional Eager Loading

```php
// Load relationship with conditions
$accounts = Account::with([
    'contacts' => function ($query) {
        $query->where('Email', '!=', null)
              ->orderBy('FirstName');
    }
])->get();

// Load multiple with conditions
$accounts = Account::with([
    'contacts' => function ($query) {
        $query->where('Email', '!=', null);
    },
    'opportunities' => function ($query) {
        $query->where('Stage', 'Closed Won')
              ->orderBy('CloseDate', 'desc');
    }
])->get();
```

## Lazy Loading

Load relationships on-demand after retrieving the model.

### Basic Lazy Loading

```php
$account = Account::find($id);

// Contacts loaded when accessed
$contacts = $account->contacts;
```

### Lazy Load with Conditions

```php
$account = Account::find($id);

// Load only active contacts
$activeContacts = $account->contacts()
    ->where('IsActive__c', true)
    ->get();

// Load recent opportunities
$recentOpportunities = $account->opportunities()
    ->whereDate('CreatedDate', '>=', now()->subDays(30))
    ->get();
```

### Checking if Loaded

```php
$account = Account::find($id);

// Check if relationship is loaded
if ($account->relationLoaded('contacts')) {
    echo "Contacts already loaded";
}

// Load if not loaded
if (!$account->relationLoaded('contacts')) {
    $account->load('contacts');
}
```

## Querying Relationships

### Has Relationship

Query parent based on existence of children:

```php
// Accounts that have at least one contact
$accounts = Account::has('contacts')->get();

// Accounts with more than 5 contacts
$accounts = Account::has('contacts', '>', 5)->get();

// Accounts with at least one closed-won opportunity
$accounts = Account::has('opportunities')
    ->whereHas('opportunities', function ($query) {
        $query->where('StageName', 'Closed Won');
    })
    ->get();
```

### WhereHas

Query parent based on child conditions:

```php
// Accounts with contacts having Gmail addresses
$accounts = Account::whereHas('contacts', function ($query) {
    $query->where('Email', 'LIKE', '%@gmail.com');
})->get();

// Accounts with high-value opportunities
$accounts = Account::whereHas('opportunities', function ($query) {
    $query->where('Amount', '>', 100000);
})->get();
```

### Doesn't Have

Query for parents without children:

```php
// Accounts without contacts
$accounts = Account::doesntHave('contacts')->get();

// Accounts without open opportunities
$accounts = Account::whereDoesntHave('opportunities', function ($query) {
    $query->where('IsClosed', false);
})->get();
```

### Counting Related Records

```php
// Get accounts with contact count
$accounts = Account::withCount('contacts')->get();

foreach ($accounts as $account) {
    echo "{$account->Name}: {$account->contacts_count} contacts\n";
}

// Multiple counts
$accounts = Account::withCount(['contacts', 'opportunities', 'cases'])->get();

foreach ($accounts as $account) {
    echo "{$account->Name}:\n";
    echo "  Contacts: {$account->contacts_count}\n";
    echo "  Opportunities: {$account->opportunities_count}\n";
    echo "  Cases: {$account->cases_count}\n";
}

// Count with conditions
$accounts = Account::withCount([
    'opportunities' => function ($query) {
        $query->where('StageName', 'Closed Won');
    }
])->get();
```

## Relationship Methods

### Create Related Records

```php
$account = Account::find($id);

// Create related contact
$contact = $account->contacts()->create([
    'FirstName' => 'John',
    'LastName' => 'Doe',
    'Email' => 'john@example.com',
]);

// AccountId is automatically set
echo $contact->AccountId; // Same as $account->Id
```

### Save Related Records

```php
$account = Account::find($id);

$contact = new Contact([
    'FirstName' => 'Jane',
    'LastName' => 'Smith',
]);

// Save and associate with account
$account->contacts()->save($contact);
```

### Associate/Dissociate (belongsTo)

```php
$contact = Contact::find($contactId);
$account = Account::find($accountId);

// Associate contact with account
$contact->account()->associate($account);
$contact->save();

// Dissociate (remove relationship)
$contact->account()->dissociate();
$contact->save();
```

## Complete Relationship Example

Here's a complete example with multiple models and relationships:

```php
<?php

namespace App\Models;

use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;

class Account extends SalesforceModel
{
    protected $table = 'Account';

    protected $fillable = ['Name', 'Industry', 'Phone', 'Website'];

    public function contacts()
    {
        return $this->hasMany(Contact::class, 'AccountId');
    }

    public function opportunities()
    {
        return $this->hasMany(Opportunity::class, 'AccountId');
    }

    public function primaryContact()
    {
        return $this->hasOne(Contact::class, 'AccountId')
            ->where('IsPrimary__c', true);
    }
}

class Contact extends SalesforceModel
{
    protected $table = 'Contact';

    protected $fillable = [
        'FirstName',
        'LastName',
        'Email',
        'Phone',
        'AccountId',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class, 'AccountId');
    }

    public function opportunities()
    {
        return $this->hasMany(Opportunity::class, 'ContactId');
    }
}

class Opportunity extends SalesforceModel
{
    protected $table = 'Opportunity';

    protected $fillable = [
        'Name',
        'StageName',
        'CloseDate',
        'Amount',
        'AccountId',
        'ContactId',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class, 'AccountId');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'ContactId');
    }
}

// Usage examples
class SalesController
{
    public function accountDashboard($accountId)
    {
        // Load account with all relationships
        $account = Account::with([
            'contacts' => function ($query) {
                $query->orderBy('FirstName');
            },
            'opportunities' => function ($query) {
                $query->where('IsClosed', false)
                      ->orderBy('CloseDate');
            },
            'primaryContact'
        ])->findOrFail($accountId);

        return view('sales.account-dashboard', [
            'account' => $account,
            'contacts' => $account->contacts,
            'openOpportunities' => $account->opportunities,
            'primaryContact' => $account->primaryContact,
        ]);
    }

    public function salesReport()
    {
        // Get accounts with aggregated data
        $accounts = Account::with(['opportunities', 'contacts'])
            ->withCount([
                'opportunities',
                'opportunities as won_opportunities_count' => function ($query) {
                    $query->where('StageName', 'Closed Won');
                }
            ])
            ->get();

        return view('sales.report', compact('accounts'));
    }
}
```

## Best Practices

### 1. Always Use Eager Loading for Lists

```php
// Good - Single query with eager loading
$accounts = Account::with('contacts')->get();

// Bad - N+1 queries (1 for accounts + N for each account's contacts)
$accounts = Account::all();
foreach ($accounts as $account) {
    $contacts = $account->contacts; // Separate query each time
}
```

### 2. Use Conditional Eager Loading

```php
// Good - Load only what you need
$accounts = Account::with([
    'contacts' => function ($query) {
        $query->where('Email', '!=', null)
              ->select(['Id', 'FirstName', 'LastName', 'Email', 'AccountId']);
    }
])->get();

// Avoid - Loading everything
$accounts = Account::with('contacts')->get();
```

### 3. Define Inverse Relationships

```php
// Always define both sides
class Account extends SalesforceModel
{
    public function contacts()
    {
        return $this->hasMany(Contact::class, 'AccountId');
    }
}

class Contact extends SalesforceModel
{
    public function account()
    {
        return $this->belongsTo(Account::class, 'AccountId');
    }
}
```

### 4. Use withCount for Counts

```php
// Good - Single query with count
$accounts = Account::withCount('contacts')->get();

// Bad - Separate query for each count
$accounts = Account::all();
foreach ($accounts as $account) {
    $count = $account->contacts()->count();
}
```

### 5. Null Check Relationships

```php
// Good - Check for null
if ($contact->account) {
    echo $contact->account->Name;
}

// Or use optional
echo optional($contact->account)->Name ?? 'No account';

// Bad - Can cause error
echo $contact->account->Name; // Error if account is null
```

### 6. Use Query Scopes for Common Filters

```php
class Account extends SalesforceModel
{
    public function activeContacts()
    {
        return $this->hasMany(Contact::class, 'AccountId')
            ->where('IsActive__c', true);
    }

    public function openOpportunities()
    {
        return $this->hasMany(Opportunity::class, 'AccountId')
            ->where('IsClosed', false);
    }
}

// Usage
$account = Account::find($id);
$activeContacts = $account->activeContacts;
$openOpps = $account->openOpportunities;
```

## Performance Considerations

### Avoid N+1 Queries

```php
// Problem: N+1 queries
$contacts = Contact::all(); // 1 query
foreach ($contacts as $contact) {
    echo $contact->account->Name; // N queries (one per contact)
}

// Solution: Eager loading
$contacts = Contact::with('account')->all(); // 2 queries total
foreach ($contacts as $contact) {
    echo $contact->account->Name; // No additional queries
}
```

### Select Only Needed Columns

```php
$accounts = Account::with([
    'contacts' => function ($query) {
        $query->select(['Id', 'FirstName', 'LastName', 'AccountId']);
    }
])->select(['Id', 'Name', 'Industry'])->get();
```

### Use Caching for Relationships

Relationship queries are automatically cached when query caching is enabled.

## Next Steps

- **[Querying](querying.md)** - Advanced query techniques
- **[CRUD Operations](crud.md)** - Creating and managing records
- **[Bulk Operations](bulk-operations.md)** - Working with multiple records efficiently
