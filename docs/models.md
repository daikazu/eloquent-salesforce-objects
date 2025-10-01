# Salesforce Models

Learn how to define and work with Salesforce models using the Eloquent-style interface.

## Table of Contents

- [Creating Models](#creating-models)
- [Model Configuration](#model-configuration)
- [Fillable and Guarded Attributes](#fillable-and-guarded-attributes)
- [Default Columns](#default-columns)
- [Timestamps](#timestamps)
- [Soft Deletes](#soft-deletes)
- [Custom Objects](#custom-objects)
- [Model Events](#model-events)
- [Accessing Metadata](#accessing-metadata)
- [Best Practices](#best-practices)

## Creating Models

Salesforce models extend `SalesforceModel` and represent Salesforce objects (standard or custom).

### Basic Model

```php
<?php

namespace App\Models;

use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;

class Account extends SalesforceModel
{
    protected $table = 'Account';
}
```

### Model with Fillable Fields

```php
<?php

namespace App\Models;

use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;

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
}
```

## Model Configuration

### Table Name

The `$table` property specifies the Salesforce object API name:

```php
class Account extends SalesforceModel
{
    protected $table = 'Account'; // Standard object
}

class MyCustomObject extends SalesforceModel
{
    protected $table = 'MyCustomObject__c'; // Custom object
}
```

### Primary Key

Salesforce uses `Id` as the primary key for all objects:

```php
class Account extends SalesforceModel
{
    protected $primaryKey = 'Id'; // Default, no need to specify
}
```

### Connection

All Salesforce models use the Salesforce connection automatically. You don't need to configure this.

## Fillable and Guarded Attributes

Control which fields can be mass-assigned:

### Fillable (Whitelist Approach)

Only specified fields can be mass-assigned:

```php
class Contact extends SalesforceModel
{
    protected $fillable = [
        'FirstName',
        'LastName',
        'Email',
        'Phone',
    ];
}

// These work
Contact::create([
    'FirstName' => 'John',
    'LastName' => 'Doe',
]);

// This would be ignored
Contact::create([
    'OwnerId' => 'some-id', // Not in fillable
]);
```

### Guarded (Blacklist Approach)

All fields except specified ones can be mass-assigned:

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

### Allow All Fields

```php
class Account extends SalesforceModel
{
    protected $guarded = []; // All fields fillable
}
```

**⚠️ Warning:** Be careful with `$guarded = []` as it allows all fields to be mass-assigned, including system fields.

## Default Columns

Specify which columns to retrieve by default:

```php
class Account extends SalesforceModel
{
    protected $table = 'Account';

    // Default columns fetched in queries
    protected array $defaultColumns = [
        'Id',
        'Name',
        'Industry',
        'AnnualRevenue',
        'CreatedDate',
    ];
}

// Only fetches default columns
$accounts = Account::all();

// Override to get all columns
$accounts = Account::allColumns()->get();

// Override to get specific columns
$accounts = Account::select(['Id', 'Name', 'Phone'])->get();
```

This is useful for optimizing queries when you have objects with many fields.

## Timestamps

Salesforce automatically manages `CreatedDate` and `LastModifiedDate`:

```php
class Account extends SalesforceModel
{
    // Salesforce field names for timestamps
    const CREATED_AT = 'CreatedDate';
    const UPDATED_AT = 'LastModifiedDate';
}

// Access timestamps
$account = Account::find($id);
echo $account->CreatedDate; // 2024-01-15T10:30:00.000+0000
echo $account->LastModifiedDate;
```

**Note:** Unlike Eloquent, you cannot disable timestamps on Salesforce objects as they are managed by Salesforce.

## Soft Deletes

Most Salesforce objects support soft deletes via the `IsDeleted` field:

```php
class Account extends SalesforceModel
{
    protected $table = 'Account';

    // Soft deletes are automatically handled
}

// Queries only non-deleted records
$accounts = Account::all();

// Include deleted records
$accounts = Account::withTrashed()->get();

// Only deleted records
$accounts = Account::onlyTrashed()->get();
```

### Objects Without Soft Deletes

Some Salesforce objects (like `User`) don't support soft deletes. Configure this in `config/eloquent-salesforce-objects.php`:

```php
'no_soft_deletes' => ['User', 'Profile'],
```

## Custom Objects

Custom Salesforce objects end with `__c`:

```php
class CustomProduct extends SalesforceModel
{
    protected $table = 'Custom_Product__c';

    protected $fillable = [
        'Name',
        'SKU__c',
        'Price__c',
        'Category__c',
    ];
}

// Use like any other model
$product = CustomProduct::create([
    'Name' => 'Widget',
    'SKU__c' => 'WDG-001',
    'Price__c' => 99.99,
]);
```

### Namespaced Custom Objects

If your custom objects are in a managed package:

```php
class NamespacedObject extends SalesforceModel
{
    protected $table = 'namespace__Custom_Object__c';
}
```

## Model Events

Models fire events during their lifecycle:

### Available Events

- `creating` - Before a record is created
- `created` - After a record is created
- `updating` - Before a record is updated
- `updated` - After a record is updated
- `deleting` - Before a record is deleted
- `deleted` - After a record is deleted
- `retrieved` - After a record is retrieved from Salesforce

### Using Model Events

```php
class Account extends SalesforceModel
{
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($account) {
            // Set default values
            $account->Status = $account->Status ?? 'Active';
        });

        static::updated(function ($account) {
            // Log changes
            logger()->info("Account {$account->Id} was updated");
        });
    }
}
```

### Using Observers

Create an observer:

```php
<?php

namespace App\Observers;

use App\Models\Account;

class AccountObserver
{
    public function creating(Account $account)
    {
        // Before creating
    }

    public function created(Account $account)
    {
        // After creating
    }

    public function updated(Account $account)
    {
        // After updating
        if ($account->isDirty('Industry')) {
            // Industry changed
        }
    }
}
```

Register the observer in a service provider:

```php
use App\Models\Account;
use App\Observers\AccountObserver;

public function boot()
{
    Account::observe(AccountObserver::class);
}
```

## Accessing Metadata

Get Salesforce object metadata:

```php
// Get all field definitions
$fields = Account::describe();

foreach ($fields as $field) {
    echo "{$field['name']} - {$field['type']}\n";
}

// Get picklist values
$industryOptions = Account::getPicklistValues('Industry');

foreach ($industryOptions as $option) {
    echo "{$option['label']} => {$option['value']}\n";
}
```

## Advanced Model Features

### Accessors and Mutators

Transform attribute values when getting or setting:

```php
class Contact extends SalesforceModel
{
    // Accessor - transform when retrieving
    public function getFullNameAttribute()
    {
        return "{$this->FirstName} {$this->LastName}";
    }

    // Mutator - transform when setting
    public function setEmailAttribute($value)
    {
        $this->attributes['Email'] = strtolower($value);
    }
}

// Using accessors
$contact = Contact::first();
echo $contact->full_name; // John Doe

// Using mutators
$contact->email = 'JOHN@EXAMPLE.COM';
$contact->save(); // Saves as 'john@example.com'
```

### Casting Attributes

Cast attributes to specific types:

```php
class Opportunity extends SalesforceModel
{
    protected $casts = [
        'Amount' => 'float',
        'CloseDate' => 'date',
        'IsClosed' => 'boolean',
    ];
}

$opportunity = Opportunity::first();

// Automatically cast to float
echo $opportunity->Amount; // 50000.00 (float)

// Automatically cast to Carbon date
echo $opportunity->CloseDate->format('Y-m-d');

// Automatically cast to boolean
if ($opportunity->IsClosed) {
    echo "This opportunity is closed";
}
```

### Hidden and Visible Attributes

Control which attributes are included when serializing to JSON/array:

```php
class Contact extends SalesforceModel
{
    // Hide sensitive fields
    protected $hidden = [
        'SSN__c',
        'BankAccount__c',
    ];

    // Or only show specific fields
    protected $visible = [
        'Id',
        'FirstName',
        'LastName',
        'Email',
    ];
}

$contact = Contact::first();

// SSN__c won't be included
return response()->json($contact);
```

## Best Practices

### 1. Use Fillable Instead of Guarded

Explicitly defining fillable fields is safer:

```php
// Good
protected $fillable = ['Name', 'Industry'];

// Avoid
protected $guarded = [];
```

### 2. Define Default Columns

For objects with many fields, define default columns:

```php
protected array $defaultColumns = [
    'Id',
    'Name',
    'Industry',
    'CreatedDate',
];
```

### 3. Use Relationships

Define relationships instead of manual joins:

```php
// Good
class Account extends SalesforceModel
{
    public function contacts()
    {
        return $this->hasMany(Contact::class, 'AccountId');
    }
}

$account->contacts;

// Avoid
$contacts = Contact::where('AccountId', $account->Id)->get();
```

### 4. Leverage Model Events

Use events for consistent business logic:

```php
static::creating(function ($model) {
    // Set default values
    // Validate data
    // Send notifications
});
```

### 5. Document Custom Fields

Add PHPDoc for custom fields:

```php
/**
 * @property string $Custom_Field__c Description of field
 * @property float $Revenue__c Annual revenue
 */
class Account extends SalesforceModel
{
    // ...
}
```

## Complete Example

Here's a comprehensive model example:

```php
<?php

namespace App\Models;

use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;

/**
 * @property string $Id
 * @property string $Name
 * @property string $Industry
 * @property float $AnnualRevenue
 * @property string $Phone
 * @property string $Website
 * @property string $BillingCity
 * @property string $CreatedDate
 * @property string $LastModifiedDate
 */
class Account extends SalesforceModel
{
    protected $table = 'Account';

    protected $fillable = [
        'Name',
        'Industry',
        'AnnualRevenue',
        'Phone',
        'Website',
        'BillingStreet',
        'BillingCity',
        'BillingState',
        'BillingPostalCode',
        'BillingCountry',
    ];

    protected array $defaultColumns = [
        'Id',
        'Name',
        'Industry',
        'AnnualRevenue',
        'Phone',
        'CreatedDate',
    ];

    protected $casts = [
        'AnnualRevenue' => 'float',
        'CreatedDate' => 'datetime',
        'LastModifiedDate' => 'datetime',
    ];

    protected $hidden = [
        'SystemModstamp',
    ];

    // Relationships
    public function contacts()
    {
        return $this->hasMany(Contact::class, 'AccountId');
    }

    public function opportunities()
    {
        return $this->hasMany(Opportunity::class, 'AccountId');
    }

    // Accessors
    public function getFullAddressAttribute()
    {
        return sprintf(
            "%s, %s, %s %s",
            $this->BillingCity,
            $this->BillingState,
            $this->BillingPostalCode,
            $this->BillingCountry
        );
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('IsActive__c', true);
    }

    public function scopeTechnology($query)
    {
        return $query->where('Industry', 'Technology');
    }

    // Boot method
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($account) {
            // Set defaults
            $account->Industry = $account->Industry ?? 'Other';
        });
    }
}
```

## Next Steps

- **[Querying](querying.md)** - Learn how to query Salesforce data
- **[CRUD Operations](crud.md)** - Create, read, update, and delete records
- **[Relationships](relationships.md)** - Define relationships between models
