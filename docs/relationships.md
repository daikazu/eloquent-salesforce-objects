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

### How it works

`hasMany` and `hasOne` relationships load through a SOQL child subquery, in the **same API call** as the parents:

```php
Account::with('contacts')->get();
// select Id, Name, ..., (select Id, LastName, ... from Contacts) from Account
```

- The subquery name (`Contacts`, `Line_Items__r`, …) comes from Salesforce's describe metadata, so custom objects work without configuration.
- `where`, `orderBy`, `select` and `limit` in a `with()` closure go into the subquery. `limit()` applies **per parent**: `limit(3)` gives each account up to 3 contacts.
- A `hasOne` fetches one child per parent.
- If Salesforce returns a parent's children in several pages, every page is fetched.
- Up to 20 relationships per query load this way, which is the most child subqueries Salesforce allows in one query. Any beyond that use a separate query.

`belongsTo` relationships, and `hasMany`/`hasOne` that a subquery can't express, use one extra query per relationship instead:

```php
Contact::with('account')->get();
// select ... from Contact
// select ... from Account where Id in ('001...', '001...', ...)
```

That happens when:
- the closure uses `offset()`, grouping or `distinct()`
- the relationship isn't one of the parent's child relationships in Salesforce
- the local key isn't `Id`
- `eager_load_strategy` is set to `query` in the config

`load()` and `loadMissing()` on a collection you already have also use this path, because the parents were fetched without the subquery; use `with()` before `get()` to get the single-query path.

That extra query lists the parent Ids, so they're sent in groups of 200, one query per group: a real org rejected a single list of about 600. On this path, `limit()` in a `hasMany`/`hasOne` closure throws an `InvalidArgumentException`, because SOQL has no per-parent limit outside a subquery; trim the loaded collection instead.

Nested relationships (`with('opportunities.lineItems')`) load the first level through the subquery and each deeper level with one more query.

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

// The 3 most recent opportunities for each account
$accounts = Account::with([
    'opportunities' => fn ($query) => $query->orderByDesc('CloseDate')->limit(3),
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

SOQL has no column-to-column comparisons, so Eloquent's `has()`, `whereHas()`, `doesntHave()`, `whereDoesntHave()` and `withCount()` are **not supported** and throw an `InvalidArgumentException`. Use the SOQL patterns below instead.

### Filter Parents by Their Children (Semi-Join)

Pass a closure to `whereIn` to build a SOQL semi-join. It replaces `has()` and `whereHas()`:

```php
// Accounts that have at least one contact
$accounts = Account::whereIn('Id', fn ($q) => $q->select('AccountId')->from('Contact'))->get();

// Accounts with contacts having Gmail addresses
$accounts = Account::whereIn('Id', fn ($q) => $q->select('AccountId')
    ->from('Contact')
    ->where('Email', 'LIKE', '%@gmail.com'))
    ->get();

// Accounts with high-value opportunities
$accounts = Account::whereIn('Id', fn ($q) => $q->select('AccountId')
    ->from('Opportunity')
    ->where('Amount', '>', 100000))
    ->get();
```

This sends a single query:

```sql
SELECT ... FROM Account WHERE Id IN (SELECT AccountId FROM Contact WHERE Email LIKE '%@gmail.com')
```

### Parents Without Children (Anti-Join)

`whereNotIn` replaces `doesntHave()` and `whereDoesntHave()`:

```php
// Accounts without contacts
$accounts = Account::whereNotIn('Id', fn ($q) => $q->select('AccountId')->from('Contact'))->get();

// Accounts without open opportunities
$accounts = Account::whereNotIn('Id', fn ($q) => $q->select('AccountId')
    ->from('Opportunity')
    ->where('IsClosed', false))
    ->get();
```

### Filter Children by Their Parent

Use SOQL's relationship dot notation (the relationship name, not the lookup field):

```php
// Opportunities whose account is in the Technology industry
$opportunities = Opportunity::where('Account.Industry', 'Technology')->get();
```

For a custom lookup `Parent_Account__c`, the relationship name is `Parent_Account__r`: `where('Parent_Account__r.Industry', 'Technology')`.

### Counting Related Records

`withCount()` is not available. To count one parent's children, query the relationship:

```php
$contactCount = $account->contacts()->count();
$wonCount = $account->opportunities()->where('StageName', 'Closed Won')->count();
```

To count children for a list of parents without N+1 queries, eager load the relationship and count in PHP:

```php
$accounts = Account::with(['contacts' => fn ($q) => $q->select(['Id', 'AccountId'])])->get();

foreach ($accounts as $account) {
    echo "{$account->Name}: {$account->contacts->count()} contacts\n";
}
```

For grouped counts across many records, use a raw `GROUP BY` query through `SalesforceAdapter` (see [Querying](querying.md#raw-soql-queries)).

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
        // Eager load, then count in PHP (withCount() is not supported in SOQL)
        $accounts = Account::with(['opportunities', 'contacts'])->get();

        $report = $accounts->map(fn ($account) => [
            'account' => $account,
            'opportunity_count' => $account->opportunities->count(),
            'won_opportunity_count' => $account->opportunities
                ->where('StageName', 'Closed Won')
                ->count(),
        ]);

        return view('sales.report', compact('report'));
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

### 4. Count Eager-Loaded Relations for Lists

```php
// Good - Two queries total, counted in PHP
$accounts = Account::with(['contacts' => fn ($q) => $q->select(['Id', 'AccountId'])])->get();
foreach ($accounts as $account) {
    $count = $account->contacts->count();
}

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
$contacts = Contact::with('account')->get(); // 2 queries total
foreach ($contacts as $contact) {
    echo $contact->account->Name; // No additional queries
}

// hasMany / hasOne eager loading is a single query
$accounts = Account::with('contacts')->get(); // 1 query total
```

### Select Only Needed Columns

```php
$accounts = Account::with([
    'contacts' => function ($query) {
        $query->select(['Id', 'FirstName', 'LastName', 'AccountId']);
    }
])->select(['Id', 'Name', 'Industry'])->get();
```

## Next Steps

- **[Querying](querying.md)** - Advanced query techniques
- **[CRUD Operations](crud.md)** - Creating and managing records
- **[Bulk Operations](bulk-operations.md)** - Working with multiple records efficiently
