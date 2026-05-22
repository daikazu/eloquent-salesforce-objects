# Changelog

All notable changes to `eloquent-salesforce-objects` will be documented in this file.

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
