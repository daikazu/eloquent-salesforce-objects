<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Database;

use BadMethodCallException;
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SOQLBuilder extends Builder
{
    protected array $noSoftDeletes;
    protected bool $throwExceptions;
    protected int $bulkOperationSize;
    protected bool $shouldIgnoreDefaults = false;

    public function __construct(
        private readonly SalesforceAdapter $adapter,
        QueryBuilder $query
    ) {
        $query->connection = new SOQLConnection($this->adapter);
        $query->grammar = new SOQLGrammar($query->connection);
        $query->connection->setGrammar($query->grammar);

        parent::__construct($query);

        // Cache config values for performance
        $this->noSoftDeletes = config('eloquent-salesforce-objects.no_soft_deletes', ['User']);
        $this->throwExceptions = config('eloquent-salesforce-objects.throw_exceptions', true);
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

        $this->query->grammar->setModel($model);

        $this->query->from($model->getTable());

        return $this;
    }

    /**
     * Batch operations - not yet implemented
     *
     * @param  string|null  $tag
     */
    public function batch($tag = null): never
    {
        // TODO: Implement batch operations
        throw new BadMethodCallException('Batch operations not yet implemented');
    }

    public function toSql()
    {
        $columns = implode(', ', $this->describe());
        $query = str_replace('*', $columns, parent::toSql());
        $query = str_replace('`', '', $query);

        $bindings = array_map(
            fn ($value) => Str::replace("'", "\'", $value),
            $this->getBindings()
        );

        return Str::replaceArray('?', $bindings, $query);
    }

    public function getModels($columns = ['*']): array
    {
        // Check if we should use default columns
        $defaultColumns = $this->model->getDefaultColumns();
        $useDefaults = $defaultColumns !== null && in_array('*', $columns) && ! $this->shouldIgnoreDefaults;

        if ($useDefaults) {
            $cols = $defaultColumns;

            // Always ensure Id is included
            if (! in_array('Id', $cols)) {
                array_unshift($cols, 'Id');
            }

            // Make sure the required timestamp columns are included
            if (! in_array('CreatedDate', $cols)) {
                $cols[] = 'CreatedDate';
            }

            if (! in_array('LastModifiedDate', $cols)) {
                $cols[] = 'LastModifiedDate';
            }

            // Make sure soft delete column is included if model supports soft deletes
            $supportsSoftDeletes = ! in_array($this->model->getTable(), $this->noSoftDeletes);

            if ($supportsSoftDeletes && ! in_array('IsDeleted', $cols)) {
                $cols[] = 'IsDeleted';
            }

            // Resolve the final columns through adapter
            $cols = $this->getSalesForceColumns($cols);
        } else {
            $cols = $this->getSalesForceColumns($columns);
        }

        return parent::getModels($cols);
    }

    public function cursor()
    {
        // Use defaultColumns if set and no explicit columns specified
        $defaultColumns = $this->model->getDefaultColumns();
        $shouldUseDefaults = $defaultColumns !== null &&
                           (! $this->query->columns || in_array('*', $this->query->columns)) &&
                           ! $this->shouldIgnoreDefaults;

        if ($shouldUseDefaults) {
            $cols = $defaultColumns;

            // Always ensure Id is included
            if (! in_array('Id', $cols)) {
                array_unshift($cols, 'Id');
            }

            $this->query->columns = $cols;
        }

        return parent::cursor();
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

        // Fetch one extra record to determine if there's a next page
        $this->forPage($page, $perPage + 1);

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

                // Collect results from the response
                if (isset($response['results'])) {
                    foreach ($response['results'] as $result) {
                        $results->push($result);
                    }
                } else {
                    // Fallback if response format is different
                    $results->push($response);
                }
            } catch (Exception $e) {
                // Log and handle exception based on config
                if ($this->throwExceptions) {
                    throw $e;
                }
                // Continue to next chunk if not throwing
            }
        }

        return $results;
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
        $models = collect($this->getModels());

        if ($models->isEmpty()) {
            return 0;
        }

        $table = $this->model->getTable();
        $deleted = 0;

        // Extract IDs from models
        $ids = $models->pluck('Id')->toArray();

        // Chunk into batches (Salesforce limit for composite API is 200)
        $chunks = collect($ids)->chunk($this->bulkOperationSize);

        foreach ($chunks as $chunk) {
            try {
                $response = $this->adapter->bulkDelete($table, $chunk->toArray(), $allOrNone);

                // Count successful deletes from response
                if (isset($response['results'])) {
                    foreach ($response['results'] as $result) {
                        if ($result['success'] ?? false) {
                            $deleted++;
                        }
                    }
                } else {
                    // If no detailed results, assume all succeeded
                    $deleted += $chunk->count();
                }
            } catch (Exception $e) {
                if ($allOrNone || $this->throwExceptions) {
                    throw $e;
                }
                // Continue to next chunk if not throwing
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
        $this->query->connection = new SOQLConnection($this->adapter, true);
        $this->query->connection->setGrammar($this->query->grammar);

        return $this;
    }

    /**
     * Query only soft deleted (trashed) records
     *
     * @return $this
     */
    public function onlyTrashed()
    {
        $this->query->connection = new SOQLConnection($this->adapter, true);
        $this->query->connection->setGrammar($this->query->grammar);

        return $this->where('IsDeleted', true);
    }

    /**
     * Get picklist values for a specific field
     */
    public function getPicklistValues(string $field): array
    {
        return $this->adapter->picklistValues($this->model->getTable(), $field);
    }

    public function from($table): static
    {
        $this->model->setTable($table);
        $this->query->from($table);
        return $this;
    }

    public function whereTime(...$args)
    {
        return $this->where(...$args);
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

        $result = $results[0];

        // Salesforce returns records as arrays after parsing
        if (is_array($result) && isset($result['aggregate'])) {
            return $result['aggregate'];
        }

        // Fallback for object format (shouldn't happen with current implementation)
        if (is_object($result) && property_exists($result, 'aggregate')) {
            return $result->aggregate;
        }

        return null;
    }

    /**
     * Determine if any rows exist for the current query
     */
    public function exists(): bool
    {
        // Optimize by limiting to 1 record
        $results = $this->limit(1)->get(['Id']);

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

    /**
     * Skip cache for this query
     *
     * Forces the query to execute against Salesforce API even if cached result exists.
     *
     * Example:
     * Account::withoutCache()->where('Industry', 'Tech')->get();
     *
     * @return $this
     */
    public function withoutCache(): static
    {
        $this->query->connection->cacheOptions['skip_cache'] = true;

        return $this;
    }

    /**
     * Set custom cache TTL for this query
     *
     * Override the default cache TTL with a custom value in seconds.
     *
     * Example:
     * Account::cacheFor(600)->where('Industry', 'Tech')->get(); // Cache for 10 minutes
     *
     * @param  int  $seconds  TTL in seconds
     * @return $this
     */
    public function cacheFor(int $seconds): static
    {
        $this->query->connection->cacheOptions['ttl'] = $seconds;

        return $this;
    }

    /**
     * Force cache refresh for this query
     *
     * Executes the query against Salesforce API and updates the cache with fresh results.
     *
     * Example:
     * Account::refreshCache()->where('Industry', 'Tech')->get();
     *
     * @return $this
     */
    public function refreshCache(): static
    {
        $this->query->connection->cacheOptions['refresh_cache'] = true;

        return $this;
    }

    /**
     * Add custom cache tags for this query
     *
     * Tags allow for more granular cache invalidation.
     *
     * Example:
     * Account::cacheTags(['tech-accounts'])->where('Industry', 'Tech')->get();
     *
     * @param  array  $tags  Cache tags
     * @return $this
     */
    public function cacheTags(array $tags): static
    {
        $this->query->connection->cacheOptions['tags'] = array_merge(
            $this->query->connection->cacheOptions['tags'] ?? [],
            $tags
        );

        return $this;
    }
}
