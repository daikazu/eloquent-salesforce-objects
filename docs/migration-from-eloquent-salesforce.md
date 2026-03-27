# Migrating from roblesterjr04/EloquentSalesForce

This guide covers migrating from [roblesterjr04/EloquentSalesForce](https://github.com/roblesterjr04/EloquentSalesForce) to this package. While not a drop-in replacement, the Eloquent-style interface is very similar, so most model code translates directly with namespace and property name changes.

## Table of Contents

- [Overview](#overview)
- [Installation](#installation)
- [Model Changes](#model-changes)
- [Query Syntax](#query-syntax)
- [CRUD Operations](#crud-operations)
- [Relationships](#relationships)
- [Configuration](#configuration)
- [Removed Features](#removed-features)
- [New Features](#new-features)
- [Migration Checklist](#migration-checklist)

## Overview

### What Stays the Same

The core Eloquent-style interface is nearly identical:

```php
// These work the same in both packages
$accounts = Account::where('Industry', 'Technology')->get();
$account = Account::find('001xx000003DGb2AAG');
$account = Account::create(['Name' => 'Acme Corp']);
$account->update(['Industry' => 'Finance']);
$account->delete();
$account->contacts; // hasMany
```

### What Changes

| Area | Old | New |
|------|-----|-----|
| Base class | `Lester\EloquentSalesForce\Model` | `Daikazu\EloquentSalesforceObjects\Models\SalesforceModel` |
| Columns property | `public $columns` | `protected ?array $defaultColumns` |
| Read-only property | `protected $readonly` | `protected array $readOnly` |
| Config file | `config/eloquent_sf.php` | `config/eloquent-salesforce-objects.php` |
| Facade | `SObjects::` | `SalesforceAdapter` (injected) |
| Short dates | `protected $shortDates` | Removed (handled automatically) |

## Installation

1. Remove the old package:
   ```bash
   composer remove rob-lester-jr04/eloquent-sales-force
   ```

2. Install the new package:
   ```bash
   composer require daikazu/eloquent-salesforce-objects
   ```

3. Publish the config:
   ```bash
   php artisan vendor:publish --tag="eloquent-salesforce-objects-config"
   ```

4. Your existing `config/forrest.php` and Forrest credentials continue to work as-is. Both packages use [omniphx/forrest](https://github.com/omniphx/forrest) for authentication.

## Model Changes

### Base Class

```php
// OLD
use Lester\EloquentSalesForce\Model;

class Lead extends Model
{
    // ...
}

// NEW
use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;

class Lead extends SalesforceModel
{
    // ...
}
```

### Columns / Default Fields

The `$columns` property is renamed to `$defaultColumns`. You no longer need to include system fields like `Id`, `CreatedDate`, `LastModifiedDate`, or `IsDeleted` — they're handled automatically.

```php
// OLD
public $columns = [
    'Id',
    'FirstName',
    'LastName',
    'Email',
    'Company',
    'CreatedDate',
    'LastModifiedDate',
    'IsDeleted',
];

// NEW
protected ?array $defaultColumns = [
    'FirstName',
    'LastName',
    'Email',
    'Company',
    // Id, CreatedDate, LastModifiedDate, IsDeleted are auto-included
];
```

Set `$defaultColumns = null` (the default) to select all fields.

### Date Fields

The old package required you to declare `$dates` and `$shortDates` arrays. The new package handles Salesforce date fields automatically through the `casts()` method.

```php
// OLD
protected $dates = [
    'CreatedDate',
    'LastModifiedDate',
    'Form_Fill_Date__c',
];
protected $shortDates = ['Form_Fill_Date__c'];

// NEW — standard timestamps are cast by the parent; only add custom ones
protected function casts(): array
{
    return array_merge(parent::casts(), [
        'Form_Fill_Date__c' => 'date',
    ]);
}
```

The parent `SalesforceModel` automatically casts `CreatedDate`, `LastModifiedDate`, `SystemModstamp`, `LastViewedDate`, and `LastReferencedDate`.

### Read-Only Fields

Minor rename — `$readonly` becomes `$readOnly`:

```php
// OLD
protected $readonly = ['Name', 'Formula_Field__c'];

// NEW
protected array $readOnly = ['Name', 'Formula_Field__c'];
```

### Table Name

Both packages use the same convention — class name as table, or override with `$table`:

```php
// Same in both packages
protected $table = 'Custom_Object__c';
```

### Full Model Migration Example

```php
// OLD
<?php

namespace App;

use Lester\EloquentSalesForce\Model;

class Lead extends Model
{
    protected $table = 'Lead';

    public $columns = [
        'Id',
        'FirstName',
        'LastName',
        'Email',
        'Company',
        'CreatedDate',
        'LastModifiedDate',
        'IsDeleted',
    ];

    protected $dates = [
        'CreatedDate',
        'LastModifiedDate',
    ];

    protected $readonly = ['Id'];

    public function tasks()
    {
        return $this->hasMany(Task::class, 'WhoId');
    }
}

// NEW
<?php

namespace App\Models\Salesforce;

use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;

class Lead extends SalesforceModel
{
    protected ?array $defaultColumns = [
        'FirstName',
        'LastName',
        'Email',
        'Company',
    ];

    protected array $readOnly = ['Id'];

    public function tasks()
    {
        return $this->hasMany(Task::class, 'WhoId');
    }
}
```

Or, use the model generator to scaffold it automatically:

```bash
php artisan make:salesforce-model Lead
```

## Query Syntax

Query syntax is nearly identical between both packages. All standard Eloquent-style methods work:

```php
// All of these work the same in both packages
$accounts = Account::all();
$account = Account::find($id);
$account = Account::where('Name', 'Acme')->first();
$accounts = Account::where('Industry', 'Technology')
    ->orderBy('Name')
    ->limit(10)
    ->get();
$accounts = Account::whereIn('Type', ['Customer', 'Partner'])->get();
$accounts = Account::whereNull('Industry')->get();
$count = Account::where('Industry', 'Technology')->count();
```

### Batch Queries (Removed)

The old package had a batch query feature that let you queue multiple queries and execute them in one API call:

```php
// OLD — no longer available
Lead::select(['Id', 'FirstName'])->batch();
Contact::select(['Id', 'Phone'])->batch();
$results = SObjects::runBatch();
```

This feature is not available in the new package. Execute queries individually instead.

## CRUD Operations

CRUD operations work identically:

```php
// Create
$account = Account::create(['Name' => 'Acme Corp']);

// Read
$account = Account::find('001xx000003DGb2AAG');

// Update
$account->Name = 'Updated Name';
$account->save();
// or
$account->update(['Name' => 'Updated Name']);

// Delete
$account->delete();
```

### Bulk Operations

Both packages support bulk operations. The new package provides bulk insert and delete through the query builder:

```php
// Bulk insert
Account::insert([
    ['Name' => 'Company A'],
    ['Name' => 'Company B'],
]);

// Bulk delete
Account::where('Industry', 'Obsolete')->delete();
```

## Relationships

Relationship syntax is the same:

```php
// hasMany
public function contacts()
{
    return $this->hasMany(Contact::class);
}

// hasMany with explicit foreign key
public function tasks()
{
    return $this->hasMany(Task::class, 'WhoId');
}

// belongsTo
public function account()
{
    return $this->belongsTo(Account::class, 'AccountId');
}

// hasOne
public function primaryContact()
{
    return $this->hasOne(Contact::class, 'AccountId');
}
```

The new package uses custom relationship classes (`SOQLHasMany`, `SOQLHasOne`) under the hood, but you don't need to reference them directly unless adding return types:

```php
use Daikazu\EloquentSalesforceObjects\Database\SOQLHasMany;

public function contacts(): SOQLHasMany
{
    return $this->hasMany(Contact::class);
}
```

## Configuration

### Old Config (`config/eloquent_sf.php`)

```php
// Old config keys and their new equivalents
'logging'           => 'single',           // → 'logging_channel' => null
'batch.select.size' => 25,                 // → 'batch_size' => 25
'batch.insert.size' => 200,                // → 'bulk_operation_size' => 200
'noSoftDeletesOn'   => ['User'],           // → 'no_soft_deletes' => ['User']
'syncTwoWay'        => false,              // → Removed
'syncPriority'      => 'salesforce',       // → Removed
```

### New Config (`config/eloquent-salesforce-objects.php`)

The new config is more explicit and adds several new options:

```php
return [
    'default_page_size'     => 200,
    'enable_field_mapping'  => false,          // NEW: auto snake_case <-> PascalCase
    'field_naming_convention' => 'snake_case', // NEW
    'enable_query_log'      => false,          // NEW
    'throw_exceptions'      => env('APP_DEBUG', false), // NEW: control error handling
    'logging_channel'       => null,
    'log_level'             => 'error',        // NEW
    'field_mappings'        => [],             // NEW: custom field name mappings
    'metadata_cache_ttl'    => 86400,          // NEW: describe cache (24h)
    'no_soft_deletes'       => ['User'],
    'batch_size'            => 25,
    'bulk_operation_size'   => 200,
    'model_generation'      => [...],          // NEW: model generator config
];
```

## Removed Features

### SyncsWithSalesforce Trait

The old package had a `SyncsWithSalesforce` trait for two-way sync between a local database table and Salesforce. This has been removed.

```php
// OLD — no longer available
use Lester\EloquentSalesForce\Traits\SyncsWithSalesforce;

class LocalLead extends Model
{
    use SyncsWithSalesforce;

    protected $salesForceObject = 'Lead';
    protected $salesForceFieldMap = [
        'Email' => 'email',
        'FirstName' => 'first_name',
    ];
}
```

If you relied on this, you'll need to implement your own sync logic using the Salesforce models and Laravel jobs/events.

### SObjects Facade

The static `SObjects` facade has been replaced by the `SalesforceAdapter` service, which is injected via Laravel's container:

```php
// OLD
use Facades\Lester\EloquentSalesForce\SObjects;

SObjects::authenticate();
$result = SObjects::query('SELECT Id FROM Lead LIMIT 1');

// NEW
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;

$adapter = app(SalesforceAdapter::class);
$result = $adapter->query('SELECT Id FROM Lead LIMIT 1');
// Authentication is handled automatically
```

### Other Removed Features

| Feature | Notes |
|---------|-------|
| `->batch()` query method | Execute queries individually |
| `SObjects::runBatch()` | No batch query support |
| `$shortDates` property | Dates handled automatically via casts |
| `SalesForceObject` anonymous model | Use `SalesforceModel` or a named model |
| Custom headers | Not supported |
| `$model->restore()` | Salesforce doesn't natively support UNDELETE |

## New Features

Features available in this package that weren't in the old one:

| Feature | Description |
|---------|-------------|
| [Model Generator](model-generator.md) | `php artisan make:salesforce-model` scaffolds models from live metadata |
| Field Mapping | Automatic conversion between `snake_case` and `PascalCase` field names |
| Exception Control | Configure whether API errors throw exceptions or return false |
| Metadata Caching | Describe results cached with configurable TTL |
| Aggregate Functions | `COUNT`, `SUM`, `AVG`, `MIN`, `MAX` support |
| [Apex REST](apex-rest.md) | Call custom Apex REST endpoints |
| Type Safety | Fully typed PHP 8.4+ codebase |

## Migration Checklist

### Preparation

- [ ] Identify all models extending `Lester\EloquentSalesForce\Model`
- [ ] Identify any usage of `SObjects` facade
- [ ] Identify any usage of `SyncsWithSalesforce` trait
- [ ] Identify any batch query usage (`->batch()`)
- [ ] Identify any `$shortDates` usage

### Code Updates

- [ ] Update composer dependencies (remove old, add new)
- [ ] Update base class: `Lester\EloquentSalesForce\Model` to `Daikazu\EloquentSalesforceObjects\Models\SalesforceModel`
- [ ] Rename `$columns` to `$defaultColumns` and remove system fields
- [ ] Rename `$readonly` to `$readOnly`
- [ ] Remove `$dates` arrays (use `casts()` method instead for custom dates)
- [ ] Remove `$shortDates` arrays
- [ ] Replace `SObjects::` facade calls with `SalesforceAdapter`
- [ ] Replace batch queries with individual queries
- [ ] Replace `SyncsWithSalesforce` trait usage with custom sync logic

### Configuration

- [ ] Publish new config: `php artisan vendor:publish --tag="eloquent-salesforce-objects-config"`
- [ ] Migrate settings from `config/eloquent_sf.php` to `config/eloquent-salesforce-objects.php`
- [ ] Remove old `config/eloquent_sf.php`
- [ ] Verify `config/forrest.php` credentials still work

### Testing

- [ ] Test all model queries return expected data
- [ ] Test all CRUD operations
- [ ] Test all relationships
- [ ] Test bulk operations
- [ ] Test in staging environment with live Salesforce org
