# Changelog

All notable changes to `eloquent-salesforce-objects` will be documented in this file.

## Unreleased

### Added

- **`SalesforceException::$statusCode` and `::$errorCode`** expose the HTTP status and Salesforce error code (e.g. `REQUEST_LIMIT_EXCEEDED`) of a failed call.
- **`with()` loads `hasMany` / `hasOne` through SOQL child subqueries, in one API call.** `Account::with('contacts')` now sends `select ..., (select ... from Contacts) from Account` instead of a second query listing every parent Id. That list failed at around 600 parents against a real org because the request was too large.
    - Relationship names come from describe metadata (new `AdapterInterface::childRelationshipName()`), so custom objects use their real `__r` name.
    - `where`, `orderBy`, `select` and `limit` in a `with()` closure go into the subquery, and `limit()` now applies per parent.
    - Paged child results are followed, so every child is loaded. This also applies to raw `SalesforceAdapter::query()` results with subqueries.
    - `belongsTo`, and closures using `offset()`/grouping/`distinct()`, keep using a separate query.
    - When the separate query is used (`belongsTo`, `load()`/`loadMissing()`, and fallbacks), the parent Ids are sent in groups of 200, one query per group, instead of one list that fails at around 600.
    - New config key `eager_load_strategy` (`subquery` by default, or `query` for the old behaviour), with env var `SALESFORCE_EAGER_LOAD_STRATEGY`.

### Security

- **Backslashes in string bindings are now escaped.** Before, only `'` was escaped, so a value ending in `\` could escape its own closing quote and let the next string binding rewrite the WHERE clause. Newlines, carriage returns and tabs are escaped too. If you were pre-escaping values passed to `where()`, stop: they are escaped for you, and pre-escaped values are now stored literally.

### Fixed

- **Error messages say what went wrong.** A Salesforce error with a non-JSON body (an HTML error page, or none) used to read `Query failed: null`. It now gives the HTTP status and body, e.g. `Query failed: HTTP 414 Request-URI Too Large (the request is too long: ...)`. Salesforce's JSON errors read `ERROR_CODE: message (HTTP 400)` instead of a pretty-printed JSON dump.
- **`simplePaginate()` no longer skips a record on every page.** The offset was calculated from `perPage + 1`, so page 2 at 20 per page started at row 21.
- **A query with no results is no longer mistaken for a COUNT** when a value in its WHERE clause contains `COUNT(`. Before, it returned one phantom model.
- **`toSql()` now returns the exact SOQL that would be sent.** It goes through the same binding escaping as executed queries, so booleans render as `TRUE`/`FALSE` and dates use the SOQL format. `SalesforceBatch` uses `toSql()`, so batched queries also get proper escaping now.
- **One adapter everywhere.** `AdapterInterface` is now a singleton that resolves to the same `SalesforceAdapter` instance. Queries, saves, relationship subqueries and `SalesforceBatch` all use it, so binding your own `AdapterInterface` replaces it for all of them. Before, reads always used the concrete `SalesforceAdapter`.
- **Compiling a `where` on a `SOQLGrammar` without a model no longer throws** an uninitialized-property error.
- **`limit()` inside a `with()` closure no longer sends invalid SOQL.** Laravel compiled it into a `row_number()` window function. It now works through the subquery, and on the separate-query path it throws a clear `InvalidArgumentException`.
- **`exists()` no longer runs eager loads.**
- **`cursor()` now selects the same columns as `get()`.** On a model with `$defaultColumns` it adds `CreatedDate`, `LastModifiedDate` and `IsDeleted` like `get()` does. On a model without them, or after `allColumns()`, it expands to every field instead of sending `select *`, which Salesforce rejects.
- **`cursor()` records its query in `queryHistory()`** and, with `throw_exceptions` off, logs a failed query and yields nothing instead of throwing.
- **Failed bulk `insert()` / `delete()` chunks are logged** when `throw_exceptions` is off. Before, they were skipped without any log entry.
- **Bulk `delete()` selects only `Id`** to find the records to delete, instead of fetching every column.
- **Passing a model class to the adapter respects an overridden `getTable()`.** Before, it read only the `$table` property through reflection.

### Changed

- **Eloquent queries throw `SalesforceException` instead of Laravel's `QueryException`.** Before, a failed `get()`/`first()`/`cursor()` was wrapped in `QueryException`, so `catch (SalesforceException)`, as the docs recommend, didn't catch it. Code that catches `QueryException` around Salesforce queries needs updating.
- **`MalformedQueryException` now extends `SalesforceException` and is thrown for `MALFORMED_QUERY` errors.** Before, it existed but was never thrown.
- **`join()` and all its variants (`leftJoin`, `crossJoin`, `joinSub`, `joinWhere`, ...) now throw `InvalidArgumentException`**, as the docs already said. Before, they quietly compiled into a child subquery named by pluralizing the object (`Foo__c` became `Foo__cs`), which Salesforce rejects for custom objects, and the rows came back nested rather than joined. Use `with()` for child records, `select('Account.Name')` for parent fields, or a `whereIn` semi-join to filter.
- `AdapterInterface` gains `childRelationshipName()`, `resolveFields()` and `queryHistory()`, which the query builder needs. Custom implementations of the interface must add them; `SalesforceAdapter` already has all three.

## v1.1.0 - 2026-05-22

### Added

- **Authentication resilience layer in `AuthenticationManager`** — fixes a production failure mode where concurrent workers all hammered the Salesforce OAuth endpoint after a token-cache miss, triggering 400 `unknown_error / retry your request` responses from Salesforce.
    - **Single-flight cache lock** — only one worker authenticates at a time. Concurrent callers block on a Laravel `Cache::lock(...)` and read the freshly-cached token via a double-check, instead of each issuing an independent `POST /services/oauth2/token`.
    - **Selective retry with backoff and jitter** — transient failures (Salesforce's documented `unknown_error / retry your request` envelope, HTTP 5xx, and Guzzle `ConnectException`) are retried with exponential backoff plus jitter. Permanent failures (`invalid_grant`, credential/config errors, other 4xx) fail fast and are **not** retried.
    - **Lock key scoped per connected app** — uses `sha1(consumer_key)` so multi-org setups don't collide on a shared lock.
    - **`LockTimeoutException`** surfaces as `AuthenticationException` with a clear message.

### Configuration

New `authentication` block in `config/eloquent-salesforce-objects.php`. All keys are env-tunable. Existing installations work without changes — defaults are conservative.

| Key | Default | Env var |
|-----|---------|---------|
| `authentication.retry_attempts` | `3` | `SALESFORCE_AUTH_RETRY_ATTEMPTS` |
| `authentication.retry_base_delay_ms` | `250` | `SALESFORCE_AUTH_RETRY_BASE_DELAY_MS` |
| `authentication.lock_wait_seconds` | `8` | `SALESFORCE_AUTH_LOCK_WAIT_SECONDS` |
| `authentication.lock_ttl_seconds` | `10` | `SALESFORCE_AUTH_LOCK_TTL_SECONDS` |

### Upgrade Notes

- **No breaking API changes.** The public surface of `AuthenticationManager` is unchanged.
- **Republish config to pick up the new keys** (optional — defaults work without it):
    ```bash
    php artisan vendor:publish --tag="eloquent-salesforce-objects-config" --force
    ```
- **Concurrency note** — on cache miss, `Forrest::hasToken()` is now called twice (outer fast-path + double-check inside the lock). Tests that mocked `hasToken()` with `->once()` for the cache-miss path need to be updated to `->twice()`. The hot path (token already cached) is unchanged: still a single `hasToken()` call.

## v1.0.3 - 2026-04-23

### Fixed

- **Unqualified Salesforce object names no longer collide with Laravel facade aliases** — `describe()`, `picklistValues()`, and related metadata calls on models whose object name matches a registered global alias (e.g. `Event`, `Task`, `User`, `Note`, `Case`) were throwing `"Class must extend SalesforceModel"`. `resolveObjectName()` now only treats a string as a class when it contains a namespace separator, so bare SF object names are passed through to the API as intended.

## v1.0.2 - 2026-04-01

### Fixed

- **Apex REST trailing slash handling** — `apexRest()` no longer strips trailing slashes from paths. Salesforce treats `/CreateOrder` and `/CreateOrder/` as different endpoints, so the path is now preserved as provided. Only a leading slash is added if missing.

## v1.0.1 - 2026-03-30

### Fixed

- Made `describe()`, `picklistValues()`, and `fieldMetadata()` on `HasSalesforceMetadata` trait callable statically (e.g. `Account::describe()`)
- Fixed docs referencing non-existent `getPicklistValues()` method — correct method is `picklistValues()`

## v1.0.0 - 2026-03-27

### v1.0.0 — Initial Stable Release

The first stable release of Eloquent Salesforce Objects — a Laravel package that lets you work with Salesforce objects using familiar Eloquent syntax.

#### Features

- **Eloquent-Style Models** — Define Salesforce objects as Laravel models with `SalesforceModel` base class
- **Full CRUD** — Create, read, update, and delete Salesforce records with automatic field filtering (updateable/createable)
- **Relationships** — `hasMany`, `belongsTo`, and `hasOne` with Salesforce PascalCase foreign key conventions
- **SOQL Query Builder** — Chainable query builder supporting `where`, `whereIn`, `whereNull`, `whereBetween`, `orderBy`, `limit`, `offset`, scopes, and more
- **Batch Queries** — Execute multiple SOQL queries in a single API call via `SalesforceBatch`
- **Bulk Operations** — Efficient bulk insert, update, and delete with automatic chunking (200 record limit)
- **Aggregate Functions** — `count()`, `sum()`, `avg()`, `min()`, `max()`, `exists()`
- **Pagination** — `paginate()` and `simplePaginate()` with Laravel's built-in paginator
- **Cursor Pagination** — Memory-efficient streaming with `cursor()` and automatic `nextRecordsUrl` handling
- **Soft Deletes** — `withTrashed()` and `onlyTrashed()` via Salesforce's `queryAll` and `IsDeleted` flag
- **Apex REST** — Call custom Apex REST endpoints with `apexRest()` supporting all HTTP methods
- **Model Generator** — `php artisan make:salesforce-model` scaffolds models from live Salesforce metadata with relationships, casts, and default columns
- **Default Columns** — Optimize queries by defining `$defaultColumns` on models; override with `allColumns()` or explicit `select()`
- **Metadata Caching** — Salesforce describe results cached with configurable TTL
- **Picklist Values** — Retrieve active picklist values for any field
- **SOQL Date Literals** — Full support for Salesforce date literals (`TODAY`, `LAST_N_DAYS:30`, etc.)
- **Connection Test** — `php artisan salesforce:test` verifies your Salesforce connection

#### Requirements

- PHP 8.4+
- Laravel 12.x or 13.x
- [omniphx/forrest](https://github.com/omniphx/forrest) ^3.0

#### Test Coverage

- 471 tests, 1184 assertions
- 82% code coverage
- Zero PHPStan errors at level 5

#### Credits

Heavily inspired by [roblesterjr04/EloquentSalesForce](https://github.com/roblesterjr04/EloquentSalesForce), rewritten from the ground up with modern PHP 8.4+, full type safety, and a cleaner architecture.
