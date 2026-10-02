# Migrating from roblesterjr04/EloquentSalesForce

This guide covers migrating from [roblesterjr04/EloquentSalesForce](https://github.com/roblesterjr04/EloquentSalesForce) to this package. While not a drop-in replacement, the Eloquent-style interface is very similar, so most model code translates directly with namespace and property name changes.

## Table of Contents

- [Overview](#overview)
- [Installation](#installation)
- [Salesforce Connection](#salesforce-connection)
- [Model Changes](#model-changes)
- [Query Syntax](#query-syntax)
- [CRUD Operations](#crud-operations)
- [Relationships](#relationships)
- [Configuration](#configuration)
- [Error Handling](#error-handling)
- [Removed Features](#removed-features)
- [Behavior Changes to Check](#behavior-changes-to-check)
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
| Short dates | `protected $shortDates` | Removed (use a `'date'` cast) |
| Credentials | `database.connections.soql` + `eloquent_sf.forrest` | `config/forrest.php` |
| OAuth routes | `/login/salesforce` registered for you | Define your own (WebServer flow only) |
| `belongsTo` default key | `AccountId` (guessed from the relation name) | Laravel's `account_Id`, so always pass the key |
| Failed saves | Throw | Throw only when `throw_exceptions` is true (defaults to `APP_DEBUG`) |

## Installation

1. Remove the old package:
   ```bash
   composer remove rob-lester-jr04/eloquent-sales-force
   ```

2. Install the new package:
   ```bash
   composer require daikazu/eloquent-salesforce-objects
   ```

3. Publish this package's config and Forrest's config:
   ```bash
   php artisan vendor:publish --tag="eloquent-salesforce-objects-config"
   php artisan vendor:publish --provider="Omniphx\Forrest\Providers\Laravel\ForrestServiceProvider"
   ```

4. Move your Salesforce credentials and Forrest settings into `config/forrest.php`, as described in [Salesforce Connection](#salesforce-connection).

5. Confirm the connection:
   ```bash
   php artisan salesforce:test
   ```

The new package requires PHP 8.4+, Laravel 12+ and omniphx/forrest 3.

## Salesforce Connection

Both packages use [omniphx/forrest](https://github.com/omniphx/forrest), but they configure it differently. The old package **ignored** `config/forrest.php`: it read Forrest settings from the `forrest` key of `config/eloquent_sf.php`, and pulled credentials from a `soql` connection in `config/database.php`. The new package uses Forrest's own `config/forrest.php`, so those settings have to move.

| Old location | New location |
|---|---|
| `database.connections.soql.consumerKey` / `consumerSecret` / `loginURL` / `username` / `password` | `forrest.credentials.*` (same key names) |
| `eloquent_sf.forrest.authentication` | `forrest.authentication` |
| `eloquent_sf.forrest.storage` | `forrest.storage` |
| `eloquent_sf.forrest.version` | `forrest.version` |

```php
// OLD — config/database.php
'soql' => [
    'driver' => 'soql',
    'database' => null,
    'consumerKey'    => env('SF_CONSUMER_KEY'),
    'consumerSecret' => env('SF_CONSUMER_SECRET'),
    'loginURL'       => env('SF_LOGIN_URL'),
    'username'       => env('SF_USERNAME'),
    'password'       => env('SF_PASSWORD'),
],

// NEW — config/forrest.php
'authentication' => env('SF_AUTH_METHOD', 'UserPassword'),
'credentials' => [
    'consumerKey'    => env('SF_CONSUMER_KEY'),
    'consumerSecret' => env('SF_CONSUMER_SECRET'),
    'callbackURI'    => env('SF_CALLBACK_URI'),
    'loginURL'       => env('SF_LOGIN_URL', 'https://login.salesforce.com'),
    'username'       => env('SF_USERNAME'),
    'password'       => env('SF_PASSWORD'),
],
'storage' => [
    'type'          => 'cache',
    'path'          => 'forrest_',
    'expire_in'     => 1200, // seconds
    'store_forever' => false,
],
```

The env variable names match Forrest's defaults, so your `.env` usually needs no changes. Watch these differences:

- **Storage type:** the old package defaulted to `cache` storage; Forrest 3 defaults to `session`. Use `cache` unless the app only talks to Salesforce inside web requests. Queue workers, scheduled jobs and Artisan commands have no session.
- **Token lifetime units:** the old config documented `storage.expire_in` in minutes; Forrest 3 reads it as seconds. Convert the value (`20` minutes becomes `1200`).

Once everything is moved, delete the `soql` entry from `config/database.php` and delete `config/eloquent_sf.php`.

### OAuth Routes

The old package registered `GET /login/salesforce` and `GET /login/salesforce/callback` (plus `POST /api/syncObject/{sfid}` for two-way sync). Removing the package removes these routes.

- **`UserPassword`, `ClientCredentials` or `OAuthJWT` flows:** nothing to do. The new package authenticates automatically on the first API call.
- **`UserPasswordSoap` flow:** switch to an OAuth flow (preferably `ClientCredentials`) as part of the migration. It calls SOAP API `login()`, which Salesforce is retiring with Summer '27 and already disables by default in new orgs. The new package logs a warning when it's configured. See [Choose an OAuth Authentication Flow](installation.md#choose-an-oauth-authentication-flow).
- **`WebServer` flow:** define the routes yourself:

```php
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

Route::get('/login/salesforce', fn () => Forrest::authenticate())->middleware('web');

Route::get('/login/salesforce/callback', function () {
    Forrest::callback();

    return redirect('/');
})->middleware('web');
```

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

> **Note:** When `$columns` was empty, the old package selected the fields in the object's **compact layout**. The new package selects **every field** when `$defaultColumns` is null, which is slower and can exceed SOQL length limits on wide objects. Set `$defaultColumns` on every model. The `layout` config option is gone.

Declare the property as `protected ?array`. Declaring it as `protected array` is a fatal error, because the parent class declares `?array`.

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

You may not need it at all. Before every create and update, the new package strips any field that Salesforce's describe metadata marks as not `createable` / `updateable`, so formula and system fields are already left out. `$readOnly` only affects `writeableAttributes()`; it does not filter `save()`. To stop a writable field from being mass-assigned, use `$fillable` or `$guarded`.

### Table Name

Both packages use the same convention — class name as table, or override with `$table`:

```php
// Same in both packages
protected $table = 'Custom_Object__c';
```

### Model Helpers

These helpers on the old base model have no direct equivalent:

| Old | New |
|---|---|
| `(string) $lead` / `"{$lead}"` (returned the Id) | `$lead->Id`. Casting a model to a string now returns JSON. |
| `$lead->web_link` | `rtrim(app(SalesforceAdapter::class)->getInstanceUrl(), '/') . '/' . $lead->Id` |
| `$lead->sf_attributes` | `$lead->getAttribute('attributes')` |
| `$lead->trashed()` | `(bool) $lead->IsDeleted` |
| `Lead::columns()` | `(new Lead)->getDefaultColumns()` |
| `$lead->getPicklistValues('Status')` | `Lead::picklistValues('Status')` (return shape changed, see [SObjects Facade](#sobjects-facade)) |
| `public $custom_headers` | Not supported |

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

    public function convertedAccount()
    {
        return $this->belongsTo(Account::class, 'ConvertedAccountId');
    }

    public function owner()
    {
        return $this->belongsTo(User::class);
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

    public function convertedAccount()
    {
        return $this->belongsTo(Account::class, 'ConvertedAccountId');
    }

    public function owner()
    {
        // The foreign key is now required (see Relationships)
        return $this->belongsTo(User::class, 'OwnerId');
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

### Batch Queries (New API)

The old package had a batch query feature using implicit global state. The new package has an explicit fluent API via `SalesforceBatch`:

```php
// OLD
Lead::select(['Id', 'FirstName'])->batch();
Contact::select(['Id', 'Phone'])->batch();
$results = SObjects::runBatch();
$leads = $results->results('Lead_0');

// NEW
use Daikazu\EloquentSalesforceObjects\Database\SalesforceBatch;

$results = SalesforceBatch::new()
    ->add('leads', Lead::select(['Id', 'FirstName']))
    ->add('contacts', Contact::select(['Id', 'Phone']))
    ->run();

$leads = $results->get('leads');       // Collection of Lead models
$contacts = $results->get('contacts'); // Collection of Contact models
```

Key differences:
- Named results instead of auto-generated keys like `Lead_0`
- Explicit scope — no global state or hidden queue
- Also accepts raw SOQL strings: `->add('stats', "SELECT COUNT(Id) FROM Lead")`
- Each query succeeds or fails independently: `$results->failed('leads')`

See the [Batch Queries documentation](batch-queries.md) for full details.

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
// Bulk insert — pass arrays of field data, not model instances
$results = Account::insert([
    ['Name' => 'Company A'],
    ['Name' => 'Company B'],
]);
$results->where('success', false); // per-record failures

// Bulk delete — returns the number of records deleted
Account::where('Industry', 'Obsolete')->delete();
```

If old code passed a collection of models to `insert()`, convert them to arrays first:

```php
// OLD
Lead::insert(collect([new Lead(['Email' => 'a@test.com']), new Lead(['Email' => 'b@test.com'])]));

// NEW
Lead::insert([['Email' => 'a@test.com'], ['Email' => 'b@test.com']]);
```

#### Bulk Update

`SObjects::update($models, $allOrNone)` accepted a mixed collection of models. The new `SalesforceAdapter::bulkUpdate()` takes one object type and at most 200 records per call, and there is no `Model::bulkUpdate()`:

```php
// OLD
SObjects::update($accounts, true);

// NEW
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;

$adapter = app(SalesforceAdapter::class);

$accounts->map(fn ($account) => ['Id' => $account->Id] + $account->getDirty())
    ->chunk(200)
    ->each(fn ($chunk) => $adapter->bulkUpdate('Account', $chunk->values()->all(), allOrNone: true));
```

For a mixed collection, group by `$model->getTable()` first and call `bulkUpdate()` once per object type.

## Relationships

Relationship syntax is the same, with one important exception: **`belongsTo` no longer guesses Salesforce foreign keys.** The old package turned a relation named `account` into `AccountId`. The new package uses Laravel's default, `account_Id`, which doesn't exist in Salesforce, so the relation silently returns `null`. Pass the foreign key on every `belongsTo`:

```php
// OLD — worked because the key was guessed as AccountId
return $this->belongsTo(Account::class);

// NEW — the key is required
return $this->belongsTo(Account::class, 'AccountId');
```

`hasMany` and `hasOne` still default to `{Parent}Id`, as before.

Eloquent's `has()`, `whereHas()`, `doesntHave()` and `withCount()` are not supported, because SOQL can't compare columns. Use a subquery with `whereIn` instead; see [Querying Relationships](relationships.md#querying-relationships).

The full syntax:

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
'syncTwoWayModels'  => [],                 // → Removed
'layout'            => 'describe/compactLayouts/primary', // → Removed, use $defaultColumns
'forrest'           => [...],              // → Move to config/forrest.php (see Salesforce Connection)
```

### New Config (`config/eloquent-salesforce-objects.php`)

The new config is more explicit and adds several new options:

```php
return [
    'default_page_size'     => 200,            // NEW: SALESFORCE_PAGE_SIZE
    'enable_query_log'      => false,          // NEW
    'throw_exceptions'      => env('SALESFORCE_THROW_EXCEPTIONS', env('APP_DEBUG', false)), // NEW: see Error Handling
    'logging_channel'       => null,
    'log_level'             => 'error',        // NEW
    'authentication'        => [...],          // NEW: OAuth retry and locking
    'metadata_cache_ttl'    => 86400,          // NEW: describe cache (24h)
    'no_soft_deletes'       => ['User'],
    'batch_size'            => 25,
    'bulk_operation_size'   => 200,
    'model_generation'      => [...],          // NEW: model generator config
];
```

## Error Handling

The old package threw an exception when a save or query failed. The new package only throws when the `throw_exceptions` config is true, and that **defaults to `APP_DEBUG`**. In production, failures are logged instead: `save()`, `update()` and `delete()` return `false`, and `create()` returns an unsaved model (`$model->exists === false`). Existing `try`/`catch` blocks around Salesforce writes will silently stop catching anything.

To keep the old behavior, set this in every environment:

```env
SALESFORCE_THROW_EXCEPTIONS=true
```

Otherwise, update call sites to check return values and `$model->exists`.

The exception classes changed too:

| Old | New |
|---|---|
| `Lester\EloquentSalesForce\Exceptions\RestAPIException` | `Daikazu\EloquentSalesforceObjects\Exceptions\SalesforceException` |
| `Lester\EloquentSalesForce\Exceptions\MalformedQueryException` | `Daikazu\EloquentSalesforceObjects\Exceptions\MalformedQueryException` |
| `RequestLimitExceeded`, `UnableToLockRowException` | `SalesforceException`. Check `getMessage()` for `REQUEST_LIMIT_EXCEEDED` / `UNABLE_TO_LOCK_ROW`. |

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

| Old | New |
|---|---|
| `SObjects::authenticate()` | Remove it. Authentication is automatic. |
| `SObjects::query($soql)` | `$adapter->query($soql)` |
| `SObjects::describe('Lead')` | `$adapter->describe('Lead')` or `Lead::describe()` |
| `SObjects::describe('Lead', 'fields')` | `Lead::describe()['fields']` |
| `SObjects::getPicklistValues('Lead', 'Status')` | `collect(Lead::picklistValues('Status'))->pluck('label', 'value')` |
| `SObjects::instanceUrl()` | `$adapter->getInstanceUrl()` |
| `SObjects::queryHistory()` | `$adapter->queryHistory()` (requires `SALESFORCE_QUERY_LOG=true`) |
| `SObjects::update($models)` | `$adapter->bulkUpdate($object, $records)` (see [Bulk Update](#bulk-update)) |
| `SObjects::object($record)` | A named `SalesforceModel`, or the raw `$adapter->query()` arrays |
| `SObjects::log(...)` | Laravel's `Log` facade |
| `SObjects::convert($id15)`, `SObjects::isSalesForceId($id)` | No equivalent. Keep a small helper in your app if needed. |
| Forrest pass-through (`SObjects::sobjects(...)`, `SObjects::get(...)`) | `Forrest::...` directly, or `$adapter->forrest()` |

**Picklists:** the old methods returned a `Collection` of `value => label`. The new `picklistValues()` returns a list of `['value' => ..., 'label' => ..., 'defaultValue' => bool]` with active values only, and throws if the field isn't a picklist. The `pluck('label', 'value')` form above restores the old shape.

### Testing Fakes

`SObjects::fake()`, `assertMassUpdate()` and `assertAuthenticated()` are gone. Mock the Forrest facade instead, as this package's own test suite does:

```php
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

$forrest = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
$this->app->instance('forrest', $forrest);
Forrest::swap($forrest);

Forrest::shouldReceive('hasToken')->andReturn(true);
Forrest::shouldReceive('describe')->andReturn(['fields' => [/* ... */]]);
Forrest::shouldReceive('query')->andReturn(['totalSize' => 0, 'done' => true, 'records' => []]);
```

### Other Removed Features

| Feature | Notes |
|---------|-------|
| `->batch()` query method | Use `SalesforceBatch::new()->add()->run()` instead |
| `SObjects::runBatch()` | Use `SalesforceBatch::new()->add()->run()` instead |
| `$shortDates` property | Dates handled automatically via casts |
| `SalesForceObject` anonymous model | Use `SalesforceModel` or a named model |
| Custom headers | Not supported |
| `$model->restore()` | Salesforce doesn't natively support UNDELETE |
| `make:salesforce` command | `make:salesforce-model` |
| `SyncFromSalesforce` command | Removed with the sync feature |
| `/login/salesforce` routes | Define your own (see [OAuth Routes](#oauth-routes)) |
| `join()` | Not supported. Use relationships with `with()`. |

## Behavior Changes to Check

These differences don't raise errors, so test for each one explicitly:

1. **`belongsTo` default foreign key:** relations without an explicit key return `null`. See [Relationships](#relationships).
2. **Error handling:** failed writes return `false` in production instead of throwing. See [Error Handling](#error-handling).
3. **Default columns:** `$defaultColumns = null` selects every field, not the compact layout.
4. **Write filtering:** non-`createable` / non-`updateable` fields are dropped from saves automatically.
5. **String casting:** `(string) $model` returns JSON instead of the Id.
6. **Picklists:** a list of arrays instead of a `value => label` Collection.
7. **Pagination:** the default page size is the `default_page_size` config (200), and totals are capped at 2000 by SOQL's `OFFSET` limit.

## New Features

Features available in this package that weren't in the old one:

| Feature | Description |
|---------|-------------|
| [Model Generator](model-generator.md) | `php artisan make:salesforce-model` scaffolds models from live metadata |
| [Batch Queries](batch-queries.md) | Fluent API for executing multiple SOQL queries in one API call |
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
- [ ] Identify `belongsTo()` calls without an explicit foreign key
- [ ] Identify `try`/`catch` blocks around Salesforce writes
- [ ] Identify `SObjects::fake()` usage in tests
- [ ] Check whether the app uses the `/login/salesforce` routes (WebServer flow)

### Code Updates

- [ ] Update composer dependencies (remove old, add new)
- [ ] Update base class: `Lester\EloquentSalesForce\Model` to `Daikazu\EloquentSalesforceObjects\Models\SalesforceModel`
- [ ] Rename `$columns` to `$defaultColumns` and remove system fields
- [ ] Rename `$readonly` to `$readOnly`
- [ ] Remove `$dates` arrays (use `casts()` method instead for custom dates)
- [ ] Remove `$shortDates` arrays
- [ ] Replace `SObjects::` facade calls with `SalesforceAdapter`
- [ ] Replace `->batch()` / `SObjects::runBatch()` with `SalesforceBatch::new()->add()->run()`
- [ ] Replace `SyncsWithSalesforce` trait usage with custom sync logic
- [ ] Add the foreign key to every `belongsTo()`
- [ ] Replace `(string) $model`, `web_link`, `sf_attributes`, `trashed()` and `columns()` usage
- [ ] Convert `SObjects::update()` to `SalesforceAdapter::bulkUpdate()`
- [ ] Update picklist callers for the new return shape
- [ ] Replace `SObjects::fake()` with Forrest mocks

### Configuration

- [ ] Publish new config: `php artisan vendor:publish --tag="eloquent-salesforce-objects-config"`
- [ ] Publish Forrest's config: `php artisan vendor:publish --provider="Omniphx\Forrest\Providers\Laravel\ForrestServiceProvider"`
- [ ] Move credentials from `database.connections.soql` to `forrest.credentials`
- [ ] Move `eloquent_sf.forrest` settings to `config/forrest.php`, and convert `storage.expire_in` from minutes to seconds
- [ ] Set Forrest `storage.type` to `cache` if you call Salesforce from queues, jobs or commands
- [ ] Migrate settings from `config/eloquent_sf.php` to `config/eloquent-salesforce-objects.php`
- [ ] Decide on `SALESFORCE_THROW_EXCEPTIONS` (set `true` to keep the old throwing behavior)
- [ ] Add your own `/login/salesforce` routes if you use the WebServer flow
- [ ] If `forrest.authentication` is `UserPasswordSoap`, switch to an OAuth flow (SOAP API `login()` is being retired)
- [ ] Remove the `soql` connection from `config/database.php` and delete `config/eloquent_sf.php`
- [ ] Run `php artisan salesforce:test`

### Testing

- [ ] Test all model queries return expected data
- [ ] Test all CRUD operations
- [ ] Test all relationships, especially every `belongsTo`
- [ ] Test that failed writes surface as you expect
- [ ] Test bulk operations
- [ ] Test in staging environment with live Salesforce org
