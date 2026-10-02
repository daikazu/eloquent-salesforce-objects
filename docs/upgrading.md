# Upgrading from 1.x to 2.0

2.0 makes Eloquent methods produce SOQL that Salesforce actually accepts, or fail with a clear error when SOQL can't do what was asked. Most apps need only a few changes. Work through the sections below, then run your test suite.

- [Quick checklist](#quick-checklist)
- [Errors are `SalesforceException`, not `QueryException`](#errors-are-salesforceexception-not-queryexception)
- [Don't escape `where()` values yourself](#dont-escape-where-values-yourself)
- [`join()` throws](#join-throws)
- [Other methods that now throw](#other-methods-that-now-throw)
- [Eager loading uses subqueries](#eager-loading-uses-subqueries)
- [Date and datetime values](#date-and-datetime-values)
- [Pagination uses `$defaultColumns`](#pagination-uses-defaultcolumns)
- [Custom `AdapterInterface` implementations](#custom-adapterinterface-implementations)
- [Smaller behaviour changes](#smaller-behaviour-changes)

## Quick checklist

- [ ] Replace `catch (QueryException $e)` around Salesforce queries with `catch (SalesforceException $e)`.
- [ ] Stop parsing error messages. Use `$e->errorCode` / `$e->statusCode`.
- [ ] Remove manual escaping (`addslashes()`, `str_replace("'", "\\'", ...)`) from values passed to `where()`.
- [ ] Search for `join(`, `leftJoin(`, `distinct()`, `inRandomOrder()`, `whereTime(`, `whereBetweenColumns(`, `lockForUpdate(`, `sharedLock(`, `inOrderOf(` and query-level `->increment(` on Salesforce models.
- [ ] Check any `with()` closure that calls `limit()`: it now limits per parent.
- [ ] If you implement `AdapterInterface` yourself, add the four new methods.
- [ ] If a `paginate()` caller needs fields outside `$defaultColumns`, use `allColumns()->paginate()`.
- [ ] If you compensated for timezones on datetime filters, remove the compensation.

## Errors are `SalesforceException`, not `QueryException`

A failed query (`get()`, `first()`, `count()`, `cursor()`, …) used to be wrapped in Laravel's `QueryException`, so `catch (SalesforceException)` never caught it. Queries now throw `SalesforceException` directly, like saves and adapter calls always did.

```php
// 1.x
try {
    Account::where('Name', 'Acme')->get();
} catch (\Illuminate\Database\QueryException $e) {
    // ...
}

// 2.0
use Daikazu\EloquentSalesforceObjects\Exceptions\SalesforceException;

try {
    Account::where('Name', 'Acme')->get();
} catch (SalesforceException $e) {
    $e->errorCode;  // "MALFORMED_QUERY", "REQUEST_LIMIT_EXCEEDED", ... or null
    $e->statusCode; // 400, 414, ... or null
}
```

The message format changed too: `Query failed: MALFORMED_QUERY: unexpected token: 'form' (HTTP 400)` instead of a pretty-printed JSON dump, and HTTP errors without a JSON body report the status (`HTTP 414 Request-URI Too Large`) instead of `null`. If you parsed messages, switch to `$e->errorCode`.

`MalformedQueryException` is now thrown for `MALFORMED_QUERY` errors. It extends `SalesforceException`, so existing catch blocks still work.

## Don't escape `where()` values yourself

Values are escaped for you: quotes, backslashes, newlines and tabs. 1.x didn't escape backslashes, which allowed SOQL injection. If you escaped values yourself, the escape characters are now escaped again and stored literally:

```php
// 1.x workaround: now searches for the literal text  O\'Brien
Account::where('Name', addslashes($name))->get();

// 2.0
Account::where('Name', $name)->get();
```

`toSql()` now shows exactly what's sent, with the same escaping, `TRUE`/`FALSE` for booleans and SOQL date formats. Tests that asserted the old `toSql()` output (for example `= 1` for `true`) need updating.

## `join()` throws

SOQL has no joins. In 1.x, `join()` quietly compiled to a child subquery named by guessing the plural (`Foo__c` became `Foo__cs`, which Salesforce rejects), and returned nested rows rather than joined ones. `join()` and every variant (`leftJoin`, `rightJoin`, `crossJoin`, `joinSub`, `joinWhere`, …) now throw `InvalidArgumentException`.

```php
// Child records: eager load
Account::with('contacts')->get();

// Parent fields: dot notation, read back as an array
$contact = Contact::select(['Id', 'LastName', 'Account.Name'])->first();
$contact->Account['Name'];

// Filter by related records: a semi-join
Account::whereIn('Id', fn ($q) => $q->select('AccountId')->from('Contact')->where('Email', 'like', '%@acme.com'))->get();
```

## Other methods that now throw

These used to produce SQL that Salesforce rejected. They now throw `InvalidArgumentException` with what to use instead:

| Method | Use instead |
|---|---|
| `distinct()` | `groupBy('Field')`, or `distinct()->count('Field')`, which now works (`COUNT_DISTINCT`) |
| `inRandomOrder()` | `->get()->shuffle()` |
| `whereTime()`, `orWhereTime()` | Compare the full datetime: `where('CreatedDate', '>=', now()->setTime(10, 0))` |
| `whereBetweenColumns()`, `whereValueBetween()` | A semi-join, or filter in PHP |
| `limit()` in a `with()` closure, when the relationship can't use a subquery | Remove `limit()` and trim the loaded collection |
| `lockForUpdate()`, `sharedLock()`, `refreshForUpdate()` | Nothing: the API has no row locking (`FOR UPDATE` is Apex-only). `lock('FOR VIEW')` / `lock('FOR REFERENCE')` still work |
| `inOrderOf()` | Sort in PHP: `->get()->sortBy(...)` |
| `increment()` / `decrement()` on a query | `$model->increment()` per record, or `update()` with explicit values |
| `insertOrIgnore()`, `insertUsing()`, `updateOrInsert()`, `saveOrIgnore()` | `upsert()` by External Id, or `updateOrCreate()` |

These now work instead of failing: query `update()`, `touch($column)` and `forceDelete()`, `upsert()` by External Id, `insertGetId()`, `$model->increment()` / `decrement()`, `toRawSql()`, `havingBetween()`, `whereBetween()` / `whereNotBetween()`, `whereYear()` / `whereMonth()` / `whereDay()`, `whereDate()` on datetime fields, `null` inside `whereIn()`, and an empty `whereNotIn()`.

## Eager loading uses subqueries

`with()` on a `hasMany` / `hasOne` now loads children in the same API call as the parents (`select …, (select … from Contacts) from Account`). In 1.x it sent a second query listing every parent Id, which failed at around 600 parents.

What to check:

- **`limit()` in a `with()` closure now applies per parent.** `with(['contacts' => fn ($q) => $q->limit(3)])` gives each account up to 3 contacts. (In 1.x it sent SQL Salesforce rejected.)
- **The raw nested field is no longer on the model.** If you read `$account->getAttributes()['Contacts']`, use `$account->contacts`.
- **To keep the old behaviour**, set `SALESFORCE_EAGER_LOAD_STRATEGY=query` (config key `eager_load_strategy`). The separate query now sends Ids in groups of 200, so it no longer fails on large result sets.

`belongsTo`, `load()` / `loadMissing()` and closures using `offset()` or grouping still use the separate query. See [Eager Loading](relationships.md#eager-loading).

## Date and datetime values

Date values are now formatted by the field's type, from Salesforce's metadata. In 1.x, date strings were quoted and Carbon values were always sent as datetimes, so most date filters failed. If you worked around that with `whereRaw()`, the plain methods now work:

```php
Opportunity::where('CloseDate', '>=', '2025-01-01')->get();          // date field
Opportunity::whereBetween('CloseDate', [$start, $end])->get();       // Carbon or strings
Account::whereDate('CreatedDate', today())->get();                    // DAY_ONLY(CreatedDate), the day in UTC
Account::whereYear('CreatedDate', 2025)->get();                       // CALENDAR_YEAR(CreatedDate)
```

**Datetime values are converted to UTC before sending.** 1.x formatted a Carbon in its own timezone and labelled it UTC, so a non-UTC Carbon was off by its offset. If you shifted values to compensate, remove the shift.

## Pagination uses `$defaultColumns`

`paginate()` and `simplePaginate()` now select the model's `$defaultColumns`, like `get()` and `cursor()`. In 1.x they selected every field unless you passed columns. If a paginated page needs other fields, pass them or use `allColumns()->paginate()`.

`simplePaginate()` also no longer skips a record on every page after the first.

## Custom `AdapterInterface` implementations

`AdapterInterface` gained four methods the query builder needs. `SalesforceAdapter` already has them; add them to your own implementation:

```php
public function bulkUpsert(string $object, string $externalIdField, array $records, bool $allOrNone = false): array;
public function childRelationshipName(string|object $parent, string $childObject, string $field): ?string;
public function resolveFields(string|object $object, array $columns = ['*']): array;
public function queryHistory(): \Illuminate\Support\Collection;
```

`AdapterInterface` is now a singleton that resolves to the `SalesforceAdapter` instance. Binding your own implementation replaces it for queries, saves, eager loading and batches. In 1.x it only affected saves.

## Smaller behaviour changes

- `chunk()`, `each()` and `lazy()` page by `Id` when the query has no `orderBy`/`offset`/`limit`, so they work past Salesforce's 2,000-record `OFFSET` cap. Chunks now arrive in `Id` order.

- `SalesforceAdapter::bulkUpdate()` accepts any number of records, sending 200 per request. `allOrNone` applies per request.
- `cursor()` selects the same columns as `get()`, and no longer sends `select *` for models without `$defaultColumns`.
- Failed bulk `insert()` / `delete()` chunks are logged when `throw_exceptions` is off.
- `exists()` no longer runs eager loads.
- Raw `SalesforceAdapter::query()` results with child subqueries now include every child, following Salesforce's pagination.

The full list is in the [CHANGELOG](../CHANGELOG.md).
