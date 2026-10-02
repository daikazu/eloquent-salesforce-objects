---
name: migrating-from-eloquent-salesforce
description: Migrate a Laravel app from roblesterjr04/EloquentSalesForce (rob-lester-jr04/eloquent-sales-force, Lester\EloquentSalesForce, ElSF, SObjects facade) to daikazu/eloquent-salesforce-objects. Use when replacing the old package, converting Lester models to SalesforceModel, porting config/eloquent_sf.php, or rewriting SObjects:: calls, ->batch() queries, SyncsWithSalesforce or SObjects::fake() usage.
---

# Migrating from roblesterjr04/EloquentSalesForce

## When to use this skill

Use this skill when an app still depends on `rob-lester-jr04/eloquent-sales-force` (namespace `Lester\EloquentSalesForce`, also called ElSF) and should move to `daikazu/eloquent-salesforce-objects`. Most model and query code carries over. The real work is in config, the `SObjects` facade, batch queries, and a few silent behavior changes listed below.

For writing new code against the new package, use the `eloquent-salesforce-objects-development` skill.

## Step 1: Inventory the old usage

Run these before changing anything, and keep the output as a checklist:

```bash
grep -rn "Lester\\\\EloquentSalesForce" app config routes tests database
grep -rn "SObjects::\|SObjects\b" app routes tests
grep -rn "->batch(\|runBatch\|SOQLBatch\|getBatch" app tests
grep -rn "SyncsWithSalesforce\|salesForceFieldMap\|SalesForceObject" app
grep -rn "public \$columns\|\$shortDates\|\$readonly\|custom_headers\|web_link\|sf_attributes" app
grep -rn "belongsTo(" app/Models
grep -rn "'soql'\|eloquent_sf" config app
grep -rn "login/salesforce\|syncObject" app routes resources
```

## Step 2: Swap the packages

```bash
composer remove rob-lester-jr04/eloquent-sales-force
composer require daikazu/eloquent-salesforce-objects
php artisan vendor:publish --tag="eloquent-salesforce-objects-config"
php artisan vendor:publish --provider="Omniphx\Forrest\Providers\Laravel\ForrestServiceProvider"
```

The new package needs PHP 8.4+, Laravel 12+ and omniphx/forrest 3.

## Step 3: Move the Salesforce connection config

The old package ignored `config/forrest.php`. It read Forrest settings from `config/eloquent_sf.php` under the `forrest` key and pulled credentials from a `soql` connection in `config/database.php`. The new package uses Forrest's own `config/forrest.php`, so move everything there:

| Old location | New location |
|---|---|
| `database.connections.soql.consumerKey` / `consumerSecret` / `loginURL` / `username` / `password` | `forrest.credentials.*` (same key names) |
| `eloquent_sf.forrest.authentication` | `forrest.authentication` |
| `eloquent_sf.forrest.storage` | `forrest.storage` |
| `eloquent_sf.forrest.version` | `forrest.version` |

Watch these defaults:

- **Storage:** the old package defaulted to `cache` storage, but Forrest 3 defaults to `session`. Set `'type' => 'cache'` in `forrest.storage` unless the app only calls Salesforce inside web requests. Queues, scheduled jobs and Artisan commands have no session.
- **Token lifetime:** the old config documented `storage.expire_in` in **minutes**, but Forrest 3 reads it as **seconds**. Convert the value (`20` becomes `1200`).
- **Env vars:** the `SF_CONSUMER_KEY`, `SF_CONSUMER_SECRET`, `SF_LOGIN_URL`, `SF_USERNAME`, `SF_PASSWORD` and `SF_AUTH_METHOD` names match Forrest's defaults, so `.env` usually needs no changes.

Then delete the `soql` entry from `config/database.php` and delete `config/eloquent_sf.php`, after porting its remaining keys:

| `config/eloquent_sf.php` | `config/eloquent-salesforce-objects.php` |
|---|---|
| `logging` | `logging_channel` (`null` = default channel, `false` = off) |
| `batch.select.size` | `batch_size` |
| `batch.insert.size` | `bulk_operation_size` |
| `noSoftDeletesOn` | `no_soft_deletes` |
| `layout` | Removed. Use `$defaultColumns` on each model (Step 4). |
| `syncTwoWay`, `syncPriority`, `syncTwoWayModels` | Removed. See Step 8. |

### OAuth routes are gone

The old package registered `GET /login/salesforce` and `GET /login/salesforce/callback`, plus `POST /api/syncObject/{sfid}` for two-way sync. Removing it removes these routes.

- Apps using the `UserPassword`, `ClientCredentials` or `OAuthJWT` flow need nothing. The new package authenticates automatically on the first API call.
- Apps using `UserPasswordSoap` must switch to an OAuth flow, preferably `ClientCredentials`. That flow calls SOAP API `login()`, which Salesforce is retiring in API versions 31.0-64.0 with Summer '27; it is already disabled by default in orgs created in Summer '26 or later. Never recommend `UserPasswordSoap` or SOAP `login()`.
- Apps using the `WebServer` flow must define their own routes:

```php
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

Route::get('/login/salesforce', fn () => Forrest::authenticate())->middleware('web');
Route::get('/login/salesforce/callback', function () {
    Forrest::callback();

    return redirect('/');
})->middleware('web');
```

Run `php artisan salesforce:test` to confirm the connection before touching models.

## Step 4: Convert models

```php
// OLD
use Lester\EloquentSalesForce\Model;

class Lead extends Model
{
    protected $table = 'Lead';
    public $columns = ['Id', 'FirstName', 'LastName', 'Email', 'CreatedDate', 'LastModifiedDate', 'IsDeleted'];
    protected $dates = ['CreatedDate', 'LastModifiedDate', 'Form_Fill_Date__c'];
    protected $shortDates = ['Form_Fill_Date__c'];
    protected $readonly = ['Score__c'];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}

// NEW
use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends SalesforceModel
{
    protected $table = 'Lead';

    protected ?array $defaultColumns = ['FirstName', 'LastName', 'Email'];

    protected array $readOnly = ['Score__c'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'Form_Fill_Date__c' => 'date',
        ]);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'AccountId');
    }
}
```

Conversion rules:

| Old | New |
|---|---|
| `extends Lester\EloquentSalesForce\Model` | `extends Daikazu\EloquentSalesforceObjects\Models\SalesforceModel` |
| `public $columns = [...]` | `protected ?array $defaultColumns = [...]`. It must be `?array`; a plain `array` type is a fatal error. Drop `Id`, `CreatedDate`, `LastModifiedDate` and `IsDeleted`, which are added automatically. |
| Empty `$columns` (selected the compact layout) | `$defaultColumns = null` selects **every field**. List the fields you need instead (see behavior changes). |
| `protected $dates = [...]` | Delete it. Standard timestamps are cast by the parent. Add custom datetime fields to `casts()` as `'datetime'`. |
| `protected $shortDates = [...]` | Delete it. Cast those fields as `'date'` and keep querying them with `whereDate()`. |
| `protected $readonly = [...]` | `protected array $readOnly = [...]`. Note the capital `O`. |
| `public $custom_headers` | Not supported. Remove it. |
| `belongsTo(Account::class)` with no foreign key | `belongsTo(Account::class, 'AccountId')`. **Required**, see behavior changes. |
| `hasMany` / `hasOne` | Unchanged. The default foreign key is still `{Parent}Id`. |
| `php artisan make:salesforce Lead` | `php artisan make:salesforce-model Lead`, which generates from live metadata. |

Regenerating a model with `php artisan make:salesforce-model {Object} --force` can be faster than hand-editing. Copy any custom methods, scopes, accessors and events across afterwards.

### Model helpers that no longer exist

| Old | New |
|---|---|
| `(string) $lead` / `"{$lead}"` (returned the Id) | `$lead->Id`. Casting a model to a string now returns JSON. |
| `$lead->web_link` | `rtrim(app(SalesforceAdapter::class)->getInstanceUrl(), '/').'/'.$lead->Id`. Add an accessor if it's used widely. |
| `$lead->sf_attributes` | `$lead->getAttribute('attributes')` |
| `$lead->trashed()` | `(bool) $lead->IsDeleted` |
| `Lead::columns()` | `(new Lead)->getDefaultColumns()` |
| `$lead->getPicklistValues('Status')` | `Lead::picklistValues('Status')` (return shape changed, see Step 5) |
| `$lead->restore()` | Throws in both packages. Remove the call. |

## Step 5: Rewrite `SObjects` facade calls

The `SObjects` facade (`Lester\EloquentSalesForce\Facades\SObjects` or the `SObjects` alias) is gone. Inject `Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter`, or resolve it with `app(SalesforceAdapter::class)`.

| Old | New |
|---|---|
| `SObjects::authenticate()` | Remove it. Authentication is automatic. |
| `SObjects::query($soql)` | `$adapter->query($soql)` |
| `SObjects::describe('Lead')` | `$adapter->describe('Lead')` or `Lead::describe()` |
| `SObjects::describe('Lead', 'fields')` | `Lead::describe()['fields']` |
| `SObjects::getPicklistValues('Lead', 'Status')` | `collect(Lead::picklistValues('Status'))->pluck('label', 'value')` |
| `SObjects::instanceUrl()` | `$adapter->getInstanceUrl()` |
| `SObjects::queryHistory()` | `$adapter->queryHistory()`. Requires `SALESFORCE_QUERY_LOG=true`. |
| `SObjects::object($record)` / `SalesForceObject` | Create a named `SalesforceModel` for that object, or work with the raw `$adapter->query()` arrays. |
| `SObjects::update($models, $allOrNone)` | `$adapter->bulkUpdate($object, $records, $allOrNone)` (below) |
| `SObjects::log(...)` | `Log::channel(...)` |
| `SObjects::convert($id15)`, `SObjects::isSalesForceId($id)` | No equivalent. Keep a small helper in the app if needed. |
| Forrest pass-through (`SObjects::sobjects(...)`, `SObjects::get(...)`, etc.) | `Forrest::...` directly, or `$adapter->forrest()` |

The old picklist call returned a `Collection` of `value => label`. The new one returns a list of `['value' => ..., 'label' => ..., 'defaultValue' => bool]` with active values only, and throws if the field is not a picklist. Use the `pluck('label', 'value')` form above wherever callers expect the old shape.

### Bulk update

`SObjects::update()` accepted a mixed collection of models. The adapter's `bulkUpdate()` takes one object type and at most 200 records per call:

```php
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;

$adapter = app(SalesforceAdapter::class);

$models->groupBy(fn ($m) => $m->getTable())->each(function ($group, $object) use ($adapter) {
    $group->map(fn ($m) => ['Id' => $m->Id] + $m->getDirty())
        ->chunk(200)
        ->each(fn ($chunk) => $adapter->bulkUpdate($object, $chunk->values()->all(), allOrNone: false));
});
```

`Model::bulkUpdate()` does not exist; always go through the adapter.

### Bulk insert

`Lead::insert($collectionOfModels)` becomes `Lead::insert($arrayOfFieldArrays)`. Pass plain arrays of field data rather than model instances:

```php
Lead::insert($leads->map(fn ($lead) => Arr::except($lead->getAttributes(), ['attributes']))->all());
```

## Step 6: Rewrite batch queries

```php
// OLD
Lead::select(['Id', 'FirstName'])->limit(100)->batch();
Lead::where('Company', 'Test')->batch('test_company');
$batch = SObjects::runBatch();
$leads = $batch->results('Lead_0');
$test = $batch->get('test_company');

// NEW
use Daikazu\EloquentSalesforceObjects\Database\SalesforceBatch;

$batch = SalesforceBatch::new()
    ->add('leads', Lead::select(['Id', 'FirstName'])->limit(100))
    ->add('test_company', Lead::where('Company', 'Test'))
    ->run();

$leads = $batch->get('leads');
$test = $batch->get('test_company');
```

- Every query needs an explicit name; there are no auto-tags like `Lead_0`. When renaming, update every `results()` / `get()` lookup.
- `new SOQLBatch()` plus `->batch($builder)` becomes `SalesforceBatch::new()->add($name, $builder)`.
- Raw SOQL strings are allowed: `->add('total', 'SELECT COUNT(Id) FROM Lead')`.
- Check failures per query with `$batch->failed('leads')`, `$batch->error('leads')` or `$batch->allSuccessful()`. `->batch()` on a builder now throws `BadMethodCallException`.

## Step 7: Exceptions and error handling

| Old exception | New |
|---|---|
| `Lester\...\Exceptions\RestAPIException` | `Daikazu\EloquentSalesforceObjects\Exceptions\SalesforceException` |
| `Lester\...\Exceptions\MalformedQueryException` | `Daikazu\EloquentSalesforceObjects\Exceptions\MalformedQueryException` |
| `RequestLimitExceeded`, `UnableToLockRowException` | `SalesforceException`. Inspect `getMessage()` for `REQUEST_LIMIT_EXCEEDED` / `UNABLE_TO_LOCK_ROW`. |

The old package threw on failed saves and queries. The new package only throws when `throw_exceptions` is true, and that setting defaults to `APP_DEBUG`. **In production, failures are logged and `save()` / `update()` / `delete()` return `false`, while `create()` returns an unsaved model.** Any `try`/`catch` around Salesforce writes will silently stop catching. Choose one:

- Set `SALESFORCE_THROW_EXCEPTIONS=true` in every environment to keep the old behavior (the safer default for a migration).
- Or convert call sites to check return values and `$model->exists`.

## Step 8: Removed features

- **`SyncsWithSalesforce` trait, the `SyncFromSalesforce` command and the `/api/syncObject/{sfid}` webhook route** have no replacement. Rebuild sync explicitly: a local model observer that dispatches a job to `create()`/`update()` the Salesforce model, and, for inbound changes, a scheduled job that polls `Model::where('LastModifiedDate', '>', $since)`. Store the Salesforce `Id` on the local row.
- **`SObjects::fake()`, `assertMassUpdate()` and `assertAuthenticated()`** are gone. Mock the Forrest facade instead:

```php
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

$forrest = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
$this->app->instance('forrest', $forrest);
Forrest::swap($forrest);

Forrest::shouldReceive('hasToken')->andReturn(true);
Forrest::shouldReceive('describe')->andReturn(['fields' => [/* ['name' => ..., 'createable' => true, 'updateable' => true] */]]);
Forrest::shouldReceive('patch')->once()->andReturn([['id' => '001...', 'success' => true]]); // bulkUpdate
```

- **`join()`** is not supported. Use relationships with `with()`, or a semi-join: `Account::whereIn('Id', fn ($q) => $q->select('AccountId')->from('Contact')->where(...))`.
- **Custom request headers** are not supported.

## Behavior changes that won't throw errors

Check each of these explicitly. Nothing fails loudly when they go wrong.

1. **`belongsTo` default foreign key.** The old package guessed `AccountId` from a relation named `account`. The new package uses Laravel's default, `account_Id`, which does not exist in Salesforce, so the relation comes back `null`. Pass the foreign key on every `belongsTo`.
2. **Default columns.** The old package selected the object's compact layout when `$columns` was empty. The new package selects every field when `$defaultColumns` is null. That is larger, slower, and can exceed SOQL length limits on wide objects. Set `$defaultColumns` on every model.
3. **Write filtering.** Before every create and update, the new package strips fields that the describe metadata marks as not `createable` / `updateable`. Formula, system and FLS-restricted fields are dropped silently instead of making Salesforce reject the request. `$readOnly` only affects `writeableAttributes()`; it does not filter `save()`.
4. **Exceptions.** See Step 7.
5. **String casting.** `(string) $model` returns JSON instead of the Id.
6. **Picklists** return a list of arrays instead of a `value => label` Collection.
7. **Pagination** selects every field unless you pass columns to `paginate()` / `simplePaginate()`. The total is capped at 2000 because of SOQL `OFFSET`.

## Step 9: Verify

1. `php artisan salesforce:test` confirms authentication.
2. Re-run the Step 1 greps. Nothing under `Lester\EloquentSalesForce`, `SObjects` or `eloquent_sf` should remain.
3. Run the test suite.
4. Against a sandbox org, exercise one read, create, update and delete per model, every `belongsTo` relation, and each batch query. Set `SALESFORCE_QUERY_LOG=true` and compare the SOQL in `app(SalesforceAdapter::class)->queryHistory()` with what the old package sent.
