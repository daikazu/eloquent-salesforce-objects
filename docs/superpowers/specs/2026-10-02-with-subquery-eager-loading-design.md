# Design: Eager Loading Child Relationships Through SOQL Subqueries

## Overview

`Account::with('contacts')->get()` makes two API calls today:

```sql
select Id, Name, ... from Account
select Id, LastName, ... from Contact where AccountId in ('001...', '001...', ...)
```

SOQL can return both in one call with a parent-to-child subquery:

```sql
select Id, Name, ..., (select Id, LastName, ... from Contacts) from Account
```

This spec covers making `with()` use that subquery for `hasMany` / `hasOne` whenever the relationship can be expressed that way. When it can't, `with()` falls back to today's two-query path. The Eloquent API stays the same, so users don't change any code.

## Why

1. **Fewer round trips.** Each eager-loaded child relationship saves one API call (and one unit of the org's daily API allowance).
2. **No IN list.** Today's second query embeds every parent Id, adding about 21 characters per parent. Long lists run into Salesforce's SOQL length and request-URI limits, so `with()` on a large parent set may already fail. Phase 0 measures where that ceiling is.
3. **Correct per-parent limits.** `with(['contacts' => fn ($q) => $q->orderByDesc('CreatedDate')->limit(5)])` currently applies the limit to *all* children combined. Laravel fixes this with window functions, which SOQL doesn't have. A subquery's `LIMIT` applies to each parent natively, so the result becomes correct.

## Costs and Salesforce limits

| Limit | Effect |
|---|---|
| Max 20 parent-to-child subqueries per query | Relationships past 20 fall back to the two-query path |
| Salesforce shrinks the outer batch size when subqueries are present | More `nextRecordsUrl` pages for big parent sets. This is usually still fewer calls than separate queries, and is already handled by `SOQLConnection` |
| A parent's children can be paginated (nested `done: false` + `nextRecordsUrl`) | Follow each one (extra calls only when a parent has many children). `ResponseParser` currently drops this metadata |
| Subqueries restrict some clauses (OFFSET, aggregates, GROUP BY, nested semi-joins) | Constraints that use them make the relationship ineligible, so it falls back |
| The relationship must exist in Salesforce metadata | Name lookup failure means fallback |

## Architecture

### 1. Relationship name lookup

The subquery needs the child relationship name (`Contacts`, `Line_Items__r`, or a custom name), not the object name. Get it from the parent's describe data, which is already cached by `SalesforceAdapter::describe()`:

```php
collect($describe['childRelationships'])
    ->first(fn ($r) => $r['childSObject'] === $related->getTable() && $r['field'] === $foreignKey)
    ['relationshipName'] ?? null;
```

Add `childRelationshipName(string|object $parent, string $childObject, string $field): ?string` to `SalesforceAdapter` and `AdapterInterface`.

### 2. Eligibility (per relation, at eager-load time)

A relation uses the subquery path only if **all** of these hold. Otherwise it uses today's path, unchanged.

- It is a `SOQLHasMany` or `SOQLHasOne`, and the related model is a `SalesforceModel`
- The local key is `Id` (child relationships always key on the parent Id)
- `childRelationshipName()` returns a name
- After the constraints closure runs, the child query uses only `select`, `where*`, `orderBy`, and `limit`: no offset, groups, havings, unions or aggregates
- No more than 20 relations on this query have already taken the subquery path

### 3. Compile

Override `SOQLBuilder::eagerLoadRelations()` (`Illuminate\Database\Eloquent\Builder::eagerLoadRelations`, called from `get()` after `getModels()`). That is too late to change the select, so the subqueries must be added before the parent query runs:

- In `SOQLBuilder::getModels()`, before calling the parent:
  1. Partition `$this->eagerLoad` into subquery-eligible relations and fallback relations.
  2. For each eligible relation, build its child query with `Relation::noConstraints()`, apply the closure, and compile it with a new `SOQLGrammar::compileChildSubquery(Builder $child, string $relationshipName)`. That method produces `select cols from RelName where ... order by ... limit n`, and `*` expands through `resolveFields()` on the child object.
  3. Inline the bindings with `SOQLConnection::substituteBindings()`, which uses the same escaping as every other query. Append the result to the parent columns as an `Expression`: `(select ... from Contacts ...)`.
  4. Remove the eligible relations from `$this->eagerLoad`, so Laravel's `eagerLoadRelations()` only handles the fallback ones.

### 4. Hydrate

After `parent::getModels()` returns, for each subquery relation on each parent model:

1. Read the raw nested value from the attribute named after the relationship (`Contacts`), then remove it and resync the original attributes. This keeps it out of `toArray()`, dirty tracking and saves.
2. Follow its `nextRecordsUrl` if present. This needs `ResponseParser::stripAttributes()` to keep `done` / `nextRecordsUrl` for nested relationship results.
3. Hydrate the rows with `$related->newCollection($related->hydrate($rows)->all())`.
4. Call `setRelation($name, $collection)`. `hasOne` gets `->first()`, or null when there are no rows.
5. Nested loads (`with('contacts.cases')`): after hydrating, call `->load('cases')` once on the combined child collection across all parents. This keeps v1 to a single nesting level. SOQL supports deeper nesting on newer API versions, but that is out of scope.

### 5. Configuration

```php
'eager_load_strategy' => env('SALESFORCE_EAGER_LOAD_STRATEGY', 'subquery'), // 'subquery' | 'query'
```

`'query'` keeps today's behaviour exactly, as an escape hatch.

## Phases

**Status (2026-10-02):** phases 0, 1, 2 and 4 are done. Phase 3 (`belongsTo`) is deferred. Splitting long IN lists on the fallback path, and the `Query failed: null` error message, are open follow-ups.

| Phase | Scope | Size |
|---|---|---|
| 0 | Characterization tests for today's `with()`: hasMany, hasOne, belongsTo, constraints, nested. There are **none** today. Also measure the IN-list ceiling against a sandbox; if `with()` already fails at N parents, log it as a bug | Small |
| 1 | Name lookup, eligibility, compile, hydrate and fallback for `hasMany` / `hasOne`, including per-parent `limit` tests | Medium, the bulk of the work |
| 2 | Nested child pagination (`nextRecordsUrl` inside a parent row), with the `ResponseParser` change | Small |
| 3 | *(Optional)* `belongsTo` through dot notation: `Contact::with('account')` → `select ..., Account.Id, Account.Name from Contact`, also one call, using the lookup field's `relationshipName` from describe | Medium |
| 4 | Docs: the Eager Loading and Performance sections in `docs/relationships.md` (they say "2 queries total"), the boost skill and the CHANGELOG | Small |

## Testing

Forrest mocks, as in `RelationshipTest`:

- `Account::with('contacts')->get()` sends **one** `Forrest::query` call, containing `(select ... from Contacts)`. The describe mock includes `childRelationships`.
- A per-parent `limit(2)` puts `limit 2` inside the subquery, and each parent gets at most 2 children.
- A custom object resolves `Line_Items__r` from describe (no pluralizing).
- Each fallback trigger (offset in the closure, a missing childRelationship entry, more than 20 relations, `eager_load_strategy = query`) produces today's two calls.
- A nested `nextRecordsUrl` for one parent is followed, and the children are merged.
- `toArray()` on a parent doesn't contain the raw `Contacts` key; `save()` doesn't send it.

## Phase 0 findings (2026-10-02)

The characterization tests are in `tests/Unit/EagerLoadingTest.php`. They confirm that today's `with()` works for:
- `hasMany`, `hasOne` and `belongsTo`
- custom foreign keys
- closure constraints and `select()`
- several relationships at once, and nested relationships (one query per level)
- `load()` on an existing collection

**Bug found: `limit()` inside a `with()` closure produces invalid SOQL.** Since Laravel 11, `HasOneOrMany::limit()` during an eager load calls `groupLimit()`, and the grammar compiles that into a SQL window function:

```sql
select * from (select ..., row_number() over (partition by AccountId) as laravel_row
from Contact where AccountId in (...)) as laravel_table where laravel_row <= 1 order by laravel_row
```

Salesforce rejects this. Phase 1 fixes it with a per-parent `LIMIT` in the subquery. The fallback path still needs a fix of its own: either throw a clear error from `SOQLGrammar` for `groupLimit`, or drop the group limit and trim per parent in PHP.

**IN-list ceiling, measured against a sandbox:** `Account::select(['Id'])->limit($n)->get()->load('contacts')` works at 400 parents and **fails at 600**. Each parent adds about 22 characters (about 27 bytes URL-encoded, because Forrest sends SOQL as a GET query string). The Contact `*` column list adds about 4 KB more, so objects with many fields fail sooner. That's a realistic size, so per decision 1 the subquery strategy is **on by default**.

**Unhelpful error:** the failure surfaced as `Query failed: null`. The underlying HTTP error (most likely URI too long) is lost when `SalesforceAdapter` wraps the exception. That's a separate fix.

**Fallback still has the ceiling:** relationships that can't use a subquery (and `belongsTo`, until phase 3) still send IN lists. Splitting a long IN list into several queries in the fallback path is a follow-up.

## Decisions (2026-10-02)

1. **Default:** on by default, with `eager_load_strategy = query` as the escape hatch. Phase 0 showed today's path fails at 600 parents.
2. **`cursor()` / `lazy()`:** not in v1. Keep Laravel's behaviour of ignoring `with()` there.
3. **`belongsTo` through dot notation (phase 3):** later, not part of this round.
