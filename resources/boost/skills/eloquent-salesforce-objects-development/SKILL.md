---
name: eloquent-salesforce-objects-development
description: Build and work with Salesforce models using daikazu/eloquent-salesforce-objects. Use when defining SalesforceModel classes, querying Salesforce with Eloquent/SOQL, creating, updating or deleting Salesforce records, bulk inserts/updates/deletes, batch queries, picklists and describe metadata, Apex REST calls, or testing code that talks to Salesforce.
---

# Eloquent Salesforce Objects Development

## When to use this skill

Use this skill when working with code that reads or writes Salesforce data through `daikazu/eloquent-salesforce-objects`: models extending `SalesforceModel`, queries against Salesforce objects, `SalesforceAdapter`, `SalesforceBatch`, or the `make:salesforce-model` command.

The package gives Salesforce objects an Eloquent interface. Queries compile to SOQL and run through [omniphx/forrest](https://github.com/omniphx/forrest), which handles OAuth and the REST API. It is **not** a database driver: there are no migrations, joins, transactions, or column-to-column comparisons.

## Setup

- Credentials live in Forrest's config (`config/forrest.php`, `SF_CONSUMER_KEY`, `SF_CONSUMER_SECRET`, `SF_LOGIN_URL`, `SF_USERNAME`, `SF_PASSWORD`, `SF_AUTH_METHOD`). The package's own config holds no credentials.
- Authenticate with an OAuth flow: `SF_AUTH_METHOD=ClientCredentials` (recommended for server-to-server), `OAuthJWT`, or `WebServer` for interactive user login. Create the app in Salesforce as an External Client App.
- **Never use or recommend `UserPasswordSoap`.** It calls SOAP API `login()`, which Salesforce is retiring in API versions 31.0-64.0 with Summer '27 and already disables by default in new orgs. The package logs a warning, and `salesforce:test` reports it, when that flow is configured.
- Use Forrest `storage.type = 'cache'` for apps that call Salesforce from queues, scheduled jobs, or Artisan commands. Session storage only works inside a web request.
- Package config: `php artisan vendor:publish --tag="eloquent-salesforce-objects-config"` creates `config/eloquent-salesforce-objects.php`.
- Verify the connection: `php artisan salesforce:test`.

## Models

Prefer generating models from live metadata:

```bash
php artisan make:salesforce-model Account
php artisan make:salesforce-model My_Object__c --all-fields --no-relationships --force
php artisan make:salesforce-model Account --path=app/Models/CRM
```

Generated models go to `app/Models/Salesforce` (`App\Models\Salesforce`) by default, configurable under `model_generation`. Customize the stub with `php artisan vendor:publish --tag=salesforce-stubs`.

A hand-written model:

```php
use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;

class Lead extends SalesforceModel
{
    // Defaults to the class basename. Set it for custom objects.
    protected $table = 'Lead';

    // Columns selected when no explicit select() is given.
    // Id, CreatedDate, LastModifiedDate and IsDeleted are added automatically.
    // null (the default) selects every field on the object.
    protected ?array $defaultColumns = [
        'FirstName',
        'LastName',
        'Email',
        'Company',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'Form_Fill_Date__c' => 'date',
            'Score__c'          => 'integer',
        ]);
    }
}
```

Rules:

- `$defaultColumns` **must** be declared `protected ?array`. Declaring it as `protected array` is a fatal error, because PHP property types are invariant.
- Attribute names are Salesforce API names (`FirstName`, `Custom_Field__c`), not snake_case: `$lead->FirstName`.
- The primary key is `Id`, a string. Records are never auto-incrementing.
- `CreatedDate`, `LastModifiedDate`, `SystemModstamp`, `LastViewedDate` and `LastReferencedDate` are cast to Carbon by the parent. When you override `casts()`, merge with `parent::casts()`.
- Salesforce manages timestamps (`$timestamps = false`). Never set `CreatedDate` or `LastModifiedDate` yourself.
- The parent sets `$guarded = []`. Add `$fillable` when the model takes user input.
- Custom objects end in `__c`; managed-package objects look like `ns__Object__c`.

## Querying

Standard builder methods compile to SOQL:

```php
Lead::where('Company', 'Acme')->first();
Lead::find('00Q1J00000cQ08eUAC');
Lead::find(['00Q...', '00Q...']);
Lead::findOrFail($id);

Opportunity::where('Amount', '>', 10000)
    ->whereIn('StageName', ['Prospecting', 'Qualification'])
    ->whereNotNull('CloseDate')
    ->orderBy('CloseDate', 'desc')
    ->limit(50)
    ->get();

Lead::where('Email', 'like', '%@example.com')->get();
Lead::where('CreatedDate', '>=', now()->subDays(7))->get();    // datetime: Carbon (converted to UTC) or '2025-01-01'
Opportunity::where('CloseDate', '>=', '2025-01-01')->get();     // date: string or Carbon, sent as 2025-01-01
Lead::whereDate('CreatedDate', today())->get();                 // DAY_ONLY(CreatedDate) = ..., the day in UTC
Lead::whereYear('CreatedDate', 2025)->get();                    // CALENDAR_YEAR(CreatedDate) = 2025

Lead::select(['Id', 'Email'])->get();  // explicit columns
Lead::allColumns()->get();             // ignore $defaultColumns, select every field

Lead::where('Status', 'Open')->count();
Opportunity::sum('Amount');            // also avg/average, min, max
Lead::where('Email', $email)->exists();
```

- Date and datetime values are formatted by the field's type from describe metadata, unquoted. Only strings that are exactly `YYYY-MM-DD` or an ISO datetime are sent unquoted; parent fields (`Account.CreatedDate`) aren't looked up, so pass Carbon there.
- `whereNull('X')` compiles to `X = null`, which is valid SOQL.
- `chunk()` and `cursor()` work for large result sets.
- `toSql()` returns the SOQL that would be sent, with bindings escaped the same way. To see queries that actually ran, read `app(SalesforceAdapter::class)->queryHistory()`.
- `whereBetween()` / `whereNotBetween()` compile to `>=`/`<=` pairs, since SOQL has no `BETWEEN`.
- Bindings are escaped automatically (quotes, backslashes, newlines). Never pre-escape values passed to `where()`.

### Not supported (these throw)

| Eloquent feature | Use instead |
|---|---|
| `has()`, `whereHas()`, `doesntHave()`, `withCount()` | A semi-join with `whereIn` and a closure (below) |
| `whereColumn()`, `whereBetweenColumns()` | A semi-join, or filter in PHP |
| `distinct()` | `groupBy('Field')`, or `distinct()->count('Field')` for `COUNT_DISTINCT()` |
| `inRandomOrder()` | `->get()->shuffle()` |
| `join()`, `leftJoin()`, `crossJoin()`, `joinSub()`, etc. | `with()` for child records, `select('Account.Name')` for parent fields, a semi-join to filter |
| `$model->restore()` | Not possible through the REST API |
| `->batch()` | `SalesforceBatch` |

Semi-join, the replacement for `whereHas`:

```php
// Accounts that have at least one Gmail contact
Account::whereIn('Id', fn ($q) => $q->select('AccountId')
    ->from('Contact')
    ->where('Email', 'like', '%@gmail.com'))
    ->get();

// Accounts with no contacts (replaces doesntHave)
Account::whereNotIn('Id', fn ($q) => $q->select('AccountId')->from('Contact'))->get();

// Filter children by a parent field with relationship dot notation
Opportunity::where('Account.Industry', 'Technology')->get();
Contact::where('Parent_Account__r.Region__c', 'EMEA')->get(); // custom lookup: __c becomes __r
```

### Pagination

```php
Lead::where('Status', 'Open')->orderBy('LastName')->paginate(25, ['Id', 'FirstName', 'LastName']);
Lead::orderBy('LastName')->simplePaginate(25);
```

- SOQL `OFFSET` caps at 2000, so the paginator total is capped at 2000.
- `paginate()` and `simplePaginate()` select **every** field unless you pass columns. They do not apply `$defaultColumns`.
- Without a page size, pagination uses the `default_page_size` config (`SALESFORCE_PAGE_SIZE`, 200 by default). A model's own `protected $perPage` overrides it.

## Relationships

```php
class Account extends SalesforceModel
{
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class); // foreign key defaults to "AccountId"
    }

    public function primaryContact(): HasOne
    {
        return $this->hasOne(Contact::class, 'AccountId');
    }
}

class Contact extends SalesforceModel
{
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'AccountId'); // always pass the foreign key
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'OwnerId');
    }
}
```

- `hasMany` and `hasOne` default the foreign key to `{ParentObject}Id` (`AccountId`). Pass it explicitly for anything else (`WhoId`, `Parent_Account__c`).
- `belongsTo` must always get the foreign key. Laravel's default (`account_Id`) does not exist in Salesforce.
- Lazy loading (`$account->contacts`) and eager loading (`Account::with('contacts')->get()`) both work. Use eager loading for lists to avoid N+1 API calls.
- `with()` loads `hasMany`/`hasOne` through a SOQL child subquery in the same API call: `select ..., (select ... from Contacts) from Account`. `where`/`orderBy`/`select`/`limit` in the closure go into the subquery, and `limit()` is per parent.
- `belongsTo`, `load()`/`loadMissing()` on an existing collection, and closures using `offset()`/grouping fall back to `WHERE ... IN (...)` queries, one per 200 parents. Prefer `with()` before `get()` for `hasMany`/`hasOne`. `limit()` in a `hasMany`/`hasOne` closure on this path throws.
- Set `eager_load_strategy` to `query` in the config to always use the fallback.

## Creating, updating, deleting

```php
$lead = Lead::create(['FirstName' => 'Ada', 'LastName' => 'Lovelace', 'Company' => 'Acme']);

$lead->Status = 'Working';
$lead->save();             // PATCHes only the dirty fields

$lead->update(['Email' => 'ada@example.com']);
$lead->delete();
Lead::where('Company', 'Test')->delete(); // bulk delete by query, returns the number deleted
```

- Before every insert or update, the package filters attributes against the object's describe metadata. Only `createable` / `updateable` fields are sent, so formula fields, system fields and fields the user cannot edit are dropped silently.
- Model events (`creating`, `created`, `updating`, `updated`, `deleting`, `deleted`) and observers work as usual.
- Soft deletes: `IsDeleted` records are excluded by default. Use `withTrashed()` or `onlyTrashed()` to include them. Objects without `IsDeleted` (such as `User`) are listed under `no_soft_deletes` in the config.

### Error handling

`throw_exceptions` (`SALESFORCE_THROW_EXCEPTIONS`) defaults to `APP_DEBUG`. **In production it is usually false**, and then failures are logged instead of thrown:

- `save()`, `update()` and `delete()` return `false`.
- `create()` still returns a model, so check `$model->exists`.
- Bulk insert skips the failed chunk and carries on.

Set `SALESFORCE_THROW_EXCEPTIONS` explicitly rather than relying on `APP_DEBUG`. When exceptions are on, catch `Daikazu\EloquentSalesforceObjects\Exceptions\SalesforceException`; queries throw it directly, not wrapped in `QueryException`. It has `$e->errorCode` (e.g. `REQUEST_LIMIT_EXCEEDED`) and `$e->statusCode`. Invalid SOQL throws its subclass `MalformedQueryException`. `AuthenticationException` lives in the same namespace.

## Bulk operations

These use the Composite SObject Collections API, up to 200 records per request (`bulk_operation_size`).

```php
// Bulk insert: arrays of field data. Chunked automatically.
$results = Contact::insert([
    ['FirstName' => 'A', 'LastName' => 'One', 'AccountId' => $accountId],
    ['FirstName' => 'B', 'LastName' => 'Two', 'AccountId' => $accountId],
], allOrNone: true);
// One save result per record, in input order:
// ['id' => '003...', 'success' => true, 'errors' => []]
$failed = $results->where('success', false);

// Bulk delete by query: chunked automatically
$deleted = Lead::where('LeadSource', 'Spam')->delete(); // number of records actually deleted
```

There is **no** `Model::bulkUpdate()`. Bulk updates go through the adapter, which accepts at most 200 records per call, so chunk them yourself:

```php
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;

$adapter = app(SalesforceAdapter::class);

Account::where('Industry', 'Tech')->get()
    ->map(fn ($a) => ['Id' => $a->Id, 'Rating' => 'Hot'])
    ->chunk(200)
    ->each(fn ($chunk) => $adapter->bulkUpdate('Account', $chunk->values()->all(), allOrNone: false));
```

## Batch queries (up to 25 queries in one API call)

```php
use Daikazu\EloquentSalesforceObjects\Database\SalesforceBatch;

$results = SalesforceBatch::new()
    ->add('leads', Lead::where('Status', 'Open')->limit(100))
    ->add('accounts', Account::select(['Id', 'Name'])->limit(50))
    ->add('total', 'SELECT COUNT(Id) FROM Opportunity') // raw SOQL is allowed
    ->run();

$results->get('leads');           // Collection of Lead models (stdClass rows for raw SOQL), or null on failure
$results->failed('accounts');     // bool
$results->error('accounts');      // error details array
$results->allSuccessful();
$results->failures();
```

Each query succeeds or fails independently.

## Metadata and picklists

Describe results are cached for `metadata_cache_ttl` seconds (24 hours by default).

```php
Account::describe();                 // full describe payload, with a 'fields' key
Account::fieldMetadata('Industry');  // one field's metadata, or null
Account::picklistValues('Industry'); // [['value' => ..., 'label' => ..., 'defaultValue' => bool], ...] (active values only)
```

`picklistValues()` throws `SalesforceException` if the field is missing or is not a picklist.

## Raw API access

Inject `SalesforceAdapter` (or resolve `Daikazu\EloquentSalesforceObjects\Contracts\AdapterInterface`) for anything the builder does not cover. Authentication is automatic. Both resolve to the same singleton. Binding your own `AdapterInterface` implementation (for example a fake in tests) replaces it for queries, saves and batches alike.

```php
public function __construct(private SalesforceAdapter $salesforce) {}

$this->salesforce->query("SELECT Id, (SELECT Id FROM Contacts) FROM Account LIMIT 10"); // ['records' => [...], 'done' => ..., 'nextRecordsUrl' => ...]
$this->salesforce->queryAll($soql);   // includes deleted/archived records
$this->salesforce->next($nextRecordsUrl);
$this->salesforce->search('FIND {Acme} IN NAME FIELDS RETURNING Account(Id, Name)'); // SOSL
$this->salesforce->upsert('Account', 'External_Id__c', 'EXT-1', ['Name' => 'Acme']);
$this->salesforce->apexRest('/orders', ['method' => 'POST', 'body' => [...], 'parameters' => [...]]);
$this->salesforce->getInstanceUrl(); // build record links: "{$url}/{$record->Id}"
$this->salesforce->forrest();        // the underlying Forrest client
```

## Testing

Never hit a real org in tests. Mock the Forrest facade the same way the package's own test suite does, and use the `array` cache driver so describe results don't leak between tests:

```php
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

beforeEach(function () {
    $forrest = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
    $this->app->instance('forrest', $forrest);
    Forrest::swap($forrest);

    Forrest::shouldReceive('hasToken')->andReturn(true);
    Forrest::shouldReceive('describe')->with('Lead')->andReturn([
        'fields' => [
            ['name' => 'Id', 'createable' => false, 'updateable' => false],
            ['name' => 'Email', 'createable' => true, 'updateable' => true],
        ],
    ]);
});

it('finds open leads', function () {
    Forrest::shouldReceive('query')
        ->once()
        ->with(Mockery::on(fn ($soql) => str_contains($soql, "Status = 'Open'")))
        ->andReturn(['totalSize' => 1, 'done' => true, 'records' => [
            ['Id' => '00Q1', 'Email' => 'a@example.com', 'attributes' => ['type' => 'Lead']],
        ]]);

    expect(Lead::where('Status', 'Open')->get())->toHaveCount(1);
});
```

## Salesforce limits to keep in mind

- Every query, save and describe is an API call that counts against the org's daily limit. Select only the columns you need, eager load relationships, and batch independent queries.
- 2000 records max per query page and a max `OFFSET` of 2000.
- 200 records max per bulk request (handled for `insert()` and query `delete()`).
- 25 queries max per `SalesforceBatch`.
