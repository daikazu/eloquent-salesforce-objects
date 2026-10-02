<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Database;

use BadMethodCallException;
use Closure;
use Daikazu\EloquentSalesforceObjects\Contracts\AdapterInterface;
use Daikazu\EloquentSalesforceObjects\Models\Concerns\LogsSalesforceErrors;
use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class SOQLBuilder extends Builder
{
    use LogsSalesforceErrors;

    protected array $noSoftDeletes;
    protected int $bulkOperationSize;
    protected bool $shouldIgnoreDefaults = false;

    /**
     * Relationships the last getModels() call loaded through child subqueries.
     *
     * @var array<int, string>
     */
    protected array $subqueryEagerLoaded = [];

    /** Salesforce allows at most 20 parent-to-child subqueries per query. */
    private const int MAX_CHILD_SUBQUERIES = 20;

    /**
     * Parents per "where key in (...)" eager-load query. Forrest sends SOQL in the URL,
     * and a real org rejected the request at ~600 Ids; 400 worked.
     */
    private const int EAGER_LOAD_CHUNK_SIZE = 200;
    private SOQLGrammar $soqlGrammar;

    public function __construct(
        private readonly AdapterInterface $adapter,
        QueryBuilder $query
    ) {
        $connection = new SOQLConnection($this->adapter);
        $this->soqlGrammar = new SOQLGrammar($connection);
        $query->connection = $connection;
        $query->grammar = $this->soqlGrammar;
        $connection->setGrammar($this->soqlGrammar);

        parent::__construct($query);

        // Cache config values for performance
        $this->noSoftDeletes = config('eloquent-salesforce-objects.no_soft_deletes', ['User']);
        $this->bulkOperationSize = config('eloquent-salesforce-objects.bulk_operation_size', 200);
    }

    /**
     * Set a model instance for the model being queried.
     *
     * @return $this
     */
    public function setModel(Model $model): static
    {
        $this->model = $model;

        if ($model instanceof SalesforceModel) {
            $this->soqlGrammar->setModel($model);
        }

        $this->query->from($model->getTable());

        return $this;
    }

    /**
     * Batch operations are handled by SalesforceBatch.
     *
     * @see SalesforceBatch
     */
    public function batch($tag = null): never
    {
        throw new BadMethodCallException(
            'Use SalesforceBatch::new()->add()->run() instead. See SalesforceBatch for details.'
        );
    }

    /**
     * Render the query as the SOQL string that would be sent to Salesforce.
     *
     * Bindings go through the same escaping as executed queries, and a bare
     * `*` column list is expanded to the object's fields.
     */
    public function toSql()
    {
        $query = $this->toBase()->clone();

        if ($query->columns === null || $query->columns === ['*']) {
            $query->columns = $this->describe();
        }

        /** @var SOQLConnection $connection */
        $connection = $query->getConnection();

        return $connection->substituteBindings($query->toSql(), $query->getBindings());
    }

    public function getModels($columns = ['*']): array
    {
        $columns = $this->resolveSelectColumns($columns);

        $this->subqueryEagerLoaded = [];
        $subqueries = $this->buildChildSubqueries();

        if ($subqueries === []) {
            return parent::getModels($columns);
        }

        // Add the child subqueries to the select list for this one query only
        $original = $this->query->columns;
        $selected = ($original === null || in_array('*', $original)) ? $columns : $original;

        $this->query->columns = [
            ...$selected,
            ...array_map(fn (array $subquery): Expression => new Expression("({$subquery['soql']})"), $subqueries),
        ];

        try {
            $models = parent::getModels($columns);
        } finally {
            $this->query->columns = $original;
        }

        $this->subqueryEagerLoaded = array_keys($subqueries);

        return $this->hydrateChildSubqueries($models, $subqueries);
    }

    /**
     * Eager load the relationships that getModels() didn't already load through a subquery.
     */
    public function eagerLoadRelations(array $models)
    {
        if ($this->subqueryEagerLoaded === []) {
            return parent::eagerLoadRelations($models);
        }

        $eagerLoad = $this->eagerLoad;

        $this->eagerLoad = array_filter(
            $eagerLoad,
            fn (string $name): bool => ! in_array(explode('.', $name)[0], $this->subqueryEagerLoaded, true),
            ARRAY_FILTER_USE_KEY
        );

        try {
            return parent::eagerLoadRelations($models);
        } finally {
            $this->eagerLoad = $eagerLoad;
            $this->subqueryEagerLoaded = [];
        }
    }

    /**
     * Run the "where key in (...)" eager load in groups of parents, so the Id list
     * stays within Salesforce's request size. A parent's children always come back
     * in its own group, so per-parent ordering is unaffected.
     *
     * @param  array<int, Model>  $models
     * @param  string  $name
     * @return array<int, Model>
     */
    protected function eagerLoadRelation(array $models, $name, Closure $constraints)
    {
        if (count($models) <= self::EAGER_LOAD_CHUNK_SIZE) {
            return parent::eagerLoadRelation($models, $name, $constraints);
        }

        $loaded = [];

        foreach (array_chunk($models, self::EAGER_LOAD_CHUNK_SIZE) as $chunk) {
            array_push($loaded, ...parent::eagerLoadRelation($chunk, $name, $constraints));
        }

        return $loaded;
    }

    /**
     * Expand ['*'] to the model's default columns, or to every field.
     *
     * @return array<int, mixed>
     */
    protected function resolveSelectColumns(array $columns): array
    {
        if (in_array('*', $columns)) {
            $columns = $this->resolveDefaultColumns() ?? $columns;
        }

        return $this->getSalesForceColumns($columns);
    }

    /**
     * Build a parent-to-child subquery for each eager-loaded hasMany/hasOne that SOQL can
     * express that way. The rest are left for the normal "where key in (...)" eager load.
     *
     * @return array<string, array{relation: HasOneOrMany, child: SOQLBuilder, key: string, soql: string}>
     */
    protected function buildChildSubqueries(): array
    {
        if (config('eloquent-salesforce-objects.eager_load_strategy', 'subquery') !== 'subquery'
            || ! $this->model instanceof SalesforceModel) {
            return [];
        }

        $subqueries = [];

        foreach ($this->eagerLoad as $name => $constraints) {
            if (str_contains($name, '.') || count($subqueries) >= self::MAX_CHILD_SUBQUERIES) {
                continue;
            }

            $subquery = $this->buildChildSubquery($name, $constraints);

            if ($subquery !== null) {
                $subqueries[$name] = $subquery;
            }
        }

        return $subqueries;
    }

    /**
     * @return array{relation: HasOneOrMany, child: SOQLBuilder, key: string, soql: string}|null
     */
    protected function buildChildSubquery(string $name, Closure $constraints): ?array
    {
        // getRelation() also queues nested relations ("contacts.cases") on the child query
        $relation = $this->getRelation($name);

        if (! ($relation instanceof SOQLHasMany || $relation instanceof SOQLHasOne)
            || ! $relation->getRelated() instanceof SalesforceModel
            || $relation->getLocalKeyName() !== 'Id') {
            return null;
        }

        $relationshipName = $this->adapter->childRelationshipName(
            $this->model,
            $relation->getRelated()->getTable(),
            $relation->getForeignKeyName()
        );

        if ($relationshipName === null) {
            return null;
        }

        $constraints($relation);

        /** @var SOQLBuilder $child */
        $child = $relation->getQuery();
        $query = $child->applyScopes()->getQuery()->clone();

        // Subqueries can't use these; leave the relationship to the normal eager load
        if ($query->offset !== null || $query->unions !== null || $query->aggregate !== null
            || ! empty($query->groups) || ! empty($query->havings) || ! empty($query->joins)
            || $query->distinct !== false || $query->lock !== null) {
            return null;
        }

        // limit() on an eager-loaded relation sets a per-parent group limit, which a
        // subquery LIMIT gives us directly. A hasOne only ever needs one row.
        $limit = $query->groupLimit['value'] ?? $query->limit;
        $query->groupLimit = null;
        $query->limit = $relation instanceof SOQLHasOne ? 1 : $limit;

        $query->columns = $child->resolveSelectColumns($query->columns ?? ['*']);
        $query->from = $relationshipName;

        /** @var SOQLConnection $connection */
        $connection = $query->getConnection();

        return [
            'relation' => $relation,
            'child'    => $child,
            'key'      => $relationshipName,
            'soql'     => $connection->substituteBindings($query->getGrammar()->compileSelect($query), $query->getBindings()),
        ];
    }

    /**
     * Turn the nested subquery results on each parent into loaded relations.
     *
     * @param  array<int, Model>  $models
     * @param  array<string, array{relation: HasOneOrMany, child: SOQLBuilder, key: string, soql: string}>  $subqueries
     * @return array<int, Model>
     */
    protected function hydrateChildSubqueries(array $models, array $subqueries): array
    {
        foreach ($subqueries as $name => ['relation' => $relation, 'child' => $child, 'key' => $key]) {
            $relation->initRelation($models, $name);

            $children = [];

            foreach ($models as $model) {
                $attributes = $model->getAttributes();
                $rows = $attributes[$key] ?? null;

                // The raw nested result isn't a field; keep it out of attributes and saves
                unset($attributes[$key]);
                $model->setRawAttributes($attributes, true);

                if (! is_array($rows) || $rows === []) {
                    continue;
                }

                $related = $child->hydrate($rows)->all();

                $model->setRelation($name, $relation instanceof SOQLHasOne
                    ? $related[0]
                    : $relation->getRelated()->newCollection($related));

                array_push($children, ...$related);
            }

            // Nested relations ("opportunities.lineItems") load on the children in one go
            if ($children !== []) {
                $child->eagerLoadRelations($children);
            }
        }

        return $models;
    }

    public function cursor()
    {
        $columns = $this->query->columns;

        // SOQL has no "select *", so expand it here; the connection's cursor() won't
        if ($columns === null || in_array('*', $columns)) {
            $this->query->columns = $this->getSalesForceColumns($this->resolveDefaultColumns() ?? ['*']);
        }

        return parent::cursor();
    }

    /**
     * The model's default columns plus the columns every query needs, or null
     * when the model has none or allColumns() was called.
     *
     * @return array<int, string>|null
     */
    protected function resolveDefaultColumns(): ?array
    {
        $defaultColumns = $this->model instanceof SalesforceModel ? $this->model->getDefaultColumns() : null;

        if ($defaultColumns === null || $this->shouldIgnoreDefaults) {
            return null;
        }

        $required = ['CreatedDate', 'LastModifiedDate'];

        if (! in_array($this->model->getTable(), $this->noSoftDeletes)) {
            $required[] = 'IsDeleted';
        }

        return array_values(array_unique(['Id', ...$defaultColumns, ...$required]));
    }

    /**
     * Chunk the results. Salesforce caps OFFSET at 2000, so a query without its own
     * order, offset or limit pages by Id instead ("Id > last order by Id").
     *
     * @param  int  $count
     */
    public function chunk($count, callable $callback): bool
    {
        return $this->canPageById()
            ? $this->chunkById($count, $callback, 'Id')
            : parent::chunk($count, $callback);
    }

    /**
     * Lazily iterate the results, paging by Id when possible (see chunk()).
     *
     * @param  int  $chunkSize
     */
    public function lazy($chunkSize = 1000)
    {
        return $this->canPageById()
            ? $this->lazyById($chunkSize, 'Id')
            : parent::lazy($chunkSize);
    }

    /**
     * Whether paging by Id returns the same rows as OFFSET paging would: only when
     * the query sets no order, offset or limit of its own.
     */
    protected function canPageById(): bool
    {
        $query = $this->getQuery();

        return empty($query->orders) && empty($query->unionOrders)
            && $query->offset === null && $query->limit === null;
    }

    /**
     * Paginate query results with full pagination info
     *
     * Runs a COUNT query to get total records, then fetches the requested page.
     * You can pass a pre-calculated $total to skip the COUNT query for better performance.
     *
     * For better performance without total counts, use simplePaginate() instead.
     *
     * @param  int|null  $perPage  Number of records per page
     * @param  array  $columns  Columns to select
     * @param  string  $pageName  Page parameter name
     * @param  int|null  $page  Current page number
     * @param  int|null  $total  Pre-calculated total (skips COUNT query if provided)
     * @return LengthAwarePaginator
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
        $columns = $this->resolveSelectColumns($columns);

        // Only run COUNT query if total wasn't provided
        if ($total === null) {
            $builder = $this->getQuery()->cloneWithout(
                ['columns', 'orders', 'limit', 'offset']
            );
            $builder->aggregate = ['function' => 'count', 'columns' => ['Id']];
            $total = $builder->get()[0]['aggregate'] ?? 0;
        }

        // SOQL OFFSET limit is 2000
        if ($total > 2000) {
            $total = 2000;
        }

        $page = $page ?: Paginator::resolveCurrentPage($pageName);
        $perPage = $perPage ?: $this->model->getPerPage();

        $results = $total
            ? $this->forPage($page, $perPage)->get($columns)
            : $this->model->newCollection();

        return $this->paginator($results, $total, $perPage, $page, [
            'path'     => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Paginate query results with simple pagination (no COUNT query)
     *
     * This is more efficient than paginate() as it doesn't run a COUNT query.
     * Only shows next/previous links without total page counts.
     *
     * Note: Fetches perPage + 1 records to determine if there's a next page.
     *
     * @param  int|null  $perPage  Number of records per page
     * @param  array  $columns  Columns to select
     * @param  string  $pageName  Page parameter name
     * @param  int|null  $page  Current page number
     * @return \Illuminate\Contracts\Pagination\Paginator
     */
    public function simplePaginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $columns = $this->resolveSelectColumns($columns);

        $page = $page ?: Paginator::resolveCurrentPage($pageName);
        $perPage = $perPage ?: $this->model->getPerPage();

        // Fetch one extra record to determine if there's a next page. The offset
        // must use $perPage, not $perPage + 1, or each page skips a record.
        $this->offset(($page - 1) * $perPage)->limit($perPage + 1);

        $results = $this->get($columns);

        return $this->simplePaginator($results, $perPage, $page, [
            'path'     => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Insert new records into Salesforce using bulk operations
     *
     * Automatically chunks records into batches of 200 (Salesforce limit)
     * and uses Composite SObject Collections API for efficient bulk inserts
     *
     * @param  bool  $allOrNone  If true, entire batch rolls back on any error
     * @return Collection Results for each record
     */
    public function insert(Collection | array $values, bool $allOrNone = false): Collection
    {
        if (is_array($values)) {
            $values = collect($values);
        }

        if ($values->isEmpty()) {
            return collect([]);
        }

        $table = $this->model->getTable();
        $results = collect([]);

        // Chunk into batches (Salesforce limit for composite API is 200)
        $chunks = $values->chunk($this->bulkOperationSize);

        foreach ($chunks as $chunk) {
            try {
                $response = $this->adapter->bulkCreate($table, $chunk->toArray(), $allOrNone);

                foreach ($this->extractSaveResults($response) ?? [$response] as $result) {
                    $results->push($result);
                }
            } catch (Exception $e) {
                // Logs, then rethrows unless throw_exceptions is off; if off, move on to the next chunk
                $this->handleSalesforceException($e, 'bulk insert');
            }
        }

        return $results;
    }

    /**
     * Extract per-record save results from a Composite SObject Collections response.
     *
     * Salesforce returns a top-level array of save results; a 'results' wrapper is
     * also accepted. Returns null when the response holds no per-record results.
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function extractSaveResults(mixed $response): ?array
    {
        if (! is_array($response)) {
            return null;
        }

        if (isset($response['results']) && is_array($response['results'])) {
            return $response['results'];
        }

        if ($response !== [] && array_is_list($response)) {
            return $response;
        }

        return null;
    }

    /**
     * Count the records a Composite SObject Collections response reports as saved.
     * Without per-record results, assume the whole request succeeded.
     */
    protected function countSuccesses(mixed $response, int $requested): int
    {
        $saveResults = $this->extractSaveResults($response);

        if ($saveResults === null) {
            return $requested;
        }

        return count(array_filter($saveResults, fn ($result): bool => (bool) ($result['success'] ?? false)));
    }

    /**
     * Update the records matching the query, 200 per Composite request.
     *
     * Like Laravel's query update(), this skips model events. Returns how many records saved.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        foreach ($values as $field => $value) {
            if ($value instanceof Expression) {
                throw new InvalidArgumentException(
                    "Cannot update [{$field}] with a raw expression: Salesforce can't compute a field from its current value. "
                    . 'Load the records, set the value and save() them instead.'
                );
            }
        }

        if ($values === []) {
            return 0;
        }

        $ids = $this->toBase()->pluck('Id');

        if ($ids->isEmpty()) {
            return 0;
        }

        $values = array_map(
            fn ($value) => $value instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z')
                : $value,
            $values
        );

        $table = $this->model->getTable();
        $updated = 0;

        foreach ($ids->chunk($this->bulkOperationSize) as $chunk) {
            $records = $chunk->map(fn ($id): array => ['Id' => $id] + $values)->values()->all();

            try {
                $updated += $this->countSuccesses($this->adapter->bulkUpdate($table, $records), count($records));
            } catch (Exception $e) {
                // Logs, then rethrows unless throw_exceptions is off; if off, move on to the next chunk
                $this->handleSalesforceException($e, 'bulk update');
            }
        }

        return $updated;
    }

    /**
     * Set the given column(s) to the current time on the matching records.
     *
     * @param  string|array<int, string>|null  $column
     */
    public function touch($column = null)
    {
        $time = $this->model->freshTimestampString();

        if ($column !== null) {
            return $this->update(array_fill_keys((array) $column, $time));
        }

        // SalesforceModel has timestamps off: LastModifiedDate is set by Salesforce
        if (! $this->model->usesTimestamps()) {
            return false;
        }

        return $this->update([$this->model->getUpdatedAtColumn() => $time]);
    }

    /**
     * Salesforce models have no soft-delete column to bypass, so this deletes like delete().
     */
    public function forceDelete(): int
    {
        return $this->delete();
    }

    /**
     * getSalesForceColumns function.
     */
    protected function getSalesForceColumns(array $columns, $table = null): array
    {
        return $this->adapter->resolveFields($table ?: $this->model->getTable(), $columns);
    }

    /**
     * describe function. returns columns of object.
     *
     * @return array
     */
    public function describe()
    {
        return (isset($this->model->columns) && count($this->model->columns))
            ? $this->model->columns
            : $this->getSalesForceColumns(['*'], $this->model->getTable());
    }

    /**
     * Delete records matching the query using bulk operations
     *
     * Automatically chunks records into batches of 200 (Salesforce limit)
     * and uses Composite SObject Collections API for efficient bulk deletes
     *
     * @param  bool  $allOrNone  If true, entire batch rolls back on any error
     * @return int Number of records deleted
     */
    public function delete($allOrNone = false): int
    {
        // Only the Ids are needed, so don't fetch every column
        $ids = $this->toBase()->pluck('Id');

        if ($ids->isEmpty()) {
            return 0;
        }

        $table = $this->model->getTable();
        $deleted = 0;

        // Chunk into batches (Salesforce limit for composite API is 200)
        $chunks = $ids->chunk($this->bulkOperationSize);

        foreach ($chunks as $chunk) {
            try {
                $response = $this->adapter->bulkDelete($table, $chunk->toArray(), $allOrNone);

                $deleted += $this->countSuccesses($response, $chunk->count());
            } catch (Exception $e) {
                // Logs, then rethrows unless throw_exceptions is off
                $this->handleSalesforceException($e, 'bulk delete');

                if ($allOrNone) {
                    throw $e;
                }
            }
        }

        return $deleted;
    }

    public function truncate(): int
    {
        return $this->delete();
    }

    /**
     * Include soft deleted (trashed) records in query results
     *
     * @return $this
     */
    public function withTrashed(): static
    {
        $connection = new SOQLConnection($this->adapter, true);
        $connection->setGrammar($this->soqlGrammar);
        $this->query->connection = $connection;

        return $this;
    }

    /**
     * Query only soft deleted (trashed) records
     */
    public function onlyTrashed(): static
    {
        $connection = new SOQLConnection($this->adapter, true);
        $connection->setGrammar($this->soqlGrammar);
        $this->query->connection = $connection;

        $this->where('IsDeleted', true);

        return $this;
    }

    /**
     * Get picklist values for a specific field
     */
    public function getPicklistValues(string $field): array
    {
        return $this->adapter->picklistValues($this->model->getTable(), $field);
    }

    public function from($table, $as = null): static
    {
        // Keep the model's table as the base table name; aliasing is handled by the query builder
        if (is_string($table)) {
            $this->model->setTable($table);
        }

        $this->query->from($table, $as);
        return $this;
    }

    /**
     * Reject join(), leftJoin(), joinSub() and friends before they reach the query builder.
     *
     * @param  string  $method
     * @param  array  $parameters
     */
    public function __call($method, $parameters)
    {
        if (stripos($method, 'join') !== false) {
            throw new InvalidArgumentException(SOQLGrammar::JOINS_UNSUPPORTED);
        }

        return parent::__call($method, $parameters);
    }

    /**
     * Add a "where column" clause to the query.
     *
     * Supports the same signatures as Laravel's whereColumn:
     * - whereColumn('first', 'second')
     * - whereColumn('first', '=', 'second')
     * - whereColumn([['first', '=', 'second'], ['foo', 'bar']])
     */
    public function whereColumn($first, $operator = null, $second = null, $boolean = 'and'): static
    {
        // Salesforce SOQL does not support column-to-column comparisons in WHERE clauses.
        // Generating such queries will lead to MALFORMED_QUERY errors like:
        //   unexpected token: 'Some__r.Field__c'
        // Eloquent's has()/whereHas()/doesntHave()/withCount() also compile to whereColumn,
        // so this is where they fail too. Suggest the semi-join SOQL does support.
        throw new InvalidArgumentException(SOQLGrammar::COLUMN_COMPARISON_UNSUPPORTED);
    }

    /**
     * Retrieve the "count" result of the query
     *
     * @param  string  $columns
     */
    public function count($columns = '*'): int
    {
        return (int) $this->aggregate(__FUNCTION__, [$columns]);
    }

    /**
     * Retrieve the sum of the values of a given column
     *
     * @param  string  $column
     * @return mixed
     */
    public function sum($column)
    {
        $result = $this->aggregate(__FUNCTION__, [$column]);

        return $result ?: 0;
    }

    /**
     * Retrieve the average of the values of a given column
     *
     * @param  string  $column
     * @return mixed
     */
    public function avg($column)
    {
        return $this->aggregate(__FUNCTION__, [$column]);
    }

    /**
     * Alias for the "avg" method
     *
     * @param  string  $column
     * @return mixed
     */
    public function average($column)
    {
        return $this->avg($column);
    }

    /**
     * Retrieve the minimum value of a given column
     *
     * @param  string  $column
     * @return mixed
     */
    public function min($column)
    {
        return $this->aggregate(__FUNCTION__, [$column]);
    }

    /**
     * Retrieve the maximum value of a given column
     *
     * @param  string  $column
     * @return mixed
     */
    public function max($column)
    {
        return $this->aggregate(__FUNCTION__, [$column]);
    }

    /**
     * Execute an aggregate function on the database
     *
     * @param  string  $function
     * @param  array  $columns
     * @return mixed
     */
    public function aggregate($function, $columns = ['*'])
    {
        // Clone the query to avoid modifying the original
        $query = $this->getQuery()->cloneWithout(['columns', 'orders', 'limit', 'offset']);

        // Set up the aggregate
        $query->aggregate = [
            'function' => $function,
            'columns'  => $columns,
        ];

        // Execute the query and return the aggregate value
        $results = $query->get();

        if (count($results) === 0) {
            return null;
        }

        // SOQLConnection returns arrays, but use data_get for type safety
        return data_get($results[0], 'aggregate');
    }

    /**
     * Determine if any rows exist for the current query
     */
    public function exists(): bool
    {
        // Query the base builder on a clone: no eager loads, and the limit doesn't stick
        $results = $this->clone()->toBase()->limit(1)->get(['Id']);

        return count($results) > 0;
    }

    /**
     * Determine if no rows exist for the current query
     */
    public function doesntExist(): bool
    {
        return ! $this->exists();
    }

    /**
     * Ignore default columns and retrieve all fields from Salesforce
     *
     * Use this method when you need to fetch all fields regardless of
     * the model's defaultColumns configuration.
     *
     * Example:
     * Account::allColumns()->get(); // Gets all fields, ignoring defaultColumns
     *
     * @return $this
     */
    public function allColumns(): static
    {
        $this->shouldIgnoreDefaults = true;

        return $this;
    }
}
