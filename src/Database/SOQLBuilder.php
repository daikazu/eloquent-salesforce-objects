<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Database;

use BadMethodCallException;
use Daikazu\EloquentSalesforceObjects\Contracts\AdapterInterface;
use Daikazu\EloquentSalesforceObjects\Models\Concerns\LogsSalesforceErrors;
use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class SOQLBuilder extends Builder
{
    use LogsSalesforceErrors;

    protected array $noSoftDeletes;
    protected int $bulkOperationSize;
    protected bool $shouldIgnoreDefaults = false;
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
        if (in_array('*', $columns)) {
            $columns = $this->resolveDefaultColumns() ?? $columns;
        }

        return parent::getModels($this->getSalesForceColumns($columns));
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
        $columns = $this->getSalesForceColumns($columns);

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
        $columns = $this->getSalesForceColumns($columns);

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

                $saveResults = $this->extractSaveResults($response);

                if ($saveResults === null) {
                    // No per-record results to inspect, so assume the whole chunk succeeded
                    $deleted += $chunk->count();
                } else {
                    $deleted += count(array_filter($saveResults, fn ($result): bool => (bool) ($result['success'] ?? false)));
                }
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
     * SOQL does not support the SQL TIME() function the same way; delegate to basic where
     */
    public function whereTime(...$args)
    {
        return $this->where(...$args);
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
        throw new InvalidArgumentException(
            'SOQL does not support column-to-column comparisons (whereColumn, has, whereHas, doesntHave, withCount). ' .
            'Use a semi-join instead: ->whereIn(\'Id\', fn ($q) => $q->select(\'Lookup__c\')->from(\'Child__c\')->where(...)).'
        );
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
        // Clone the query to avoid mutating the builder's limit state
        $query = $this->clone();

        $results = $query->limit(1)->get(['Id']);

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
