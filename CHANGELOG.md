# Changelog

All notable changes to `eloquent-salesforce-objects` will be documented in this file.

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
