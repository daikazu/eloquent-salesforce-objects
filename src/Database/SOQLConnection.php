<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Database;

use Closure;
use Daikazu\EloquentSalesforceObjects\Exceptions\AuthenticationException;
use Daikazu\EloquentSalesforceObjects\Exceptions\SalesforceException;
use Daikazu\EloquentSalesforceObjects\Models\Concerns\LogsSalesforceErrors;
use Daikazu\EloquentSalesforceObjects\Support\QueryCache;
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;
use DateTimeInterface;
use Exception;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use stdClass;

class SOQLConnection extends Connection
{
    use LogsSalesforceErrors;

    protected bool $enableQueryLog;
    protected QueryCache $queryCache;
    public array $cacheOptions = [];

    public function __construct(
        private readonly SalesforceAdapter $adapter,
        private readonly bool $queryAll = false,
    ) {
        // Cache config values for performance
        $this->enableQueryLog = config('eloquent-salesforce-objects.enable_query_log', false);
        $this->queryCache = new QueryCache;
    }

    public function setGrammar(SOQLGrammar $grammar): void
    {
        $this->queryGrammar = $grammar;
    }

    /**
     * Execute a select query against the database and handle retrieval of records.
     *
     * @param  string  $query  The SOQL query string to be executed.
     * @param  array  $bindings  An array of parameter bindings for the query.
     * @param  bool  $useReadPdo  Determines whether to use the read PDO connection.
     * @return array An array of records returned by the query.
     */
    public function select($query, $bindings = [], $useReadPdo = true): array
    {
        return $this->run($query, $bindings, function ($query, $bindings): array {
            $statement = $this->prepare($query, $bindings);

            // Wrap query execution with cache
            return $this->queryCache->remember(
                $statement,
                fn (): array => $this->executeQuery($statement),
                $this->cacheOptions
            );
        });
    }

    /**
     * Execute the actual Salesforce query (cache-aware)
     *
     * @param  string  $statement  Prepared SOQL statement
     * @return array Query results
     */
    protected function executeQuery(string $statement): array
    {
        try {
            // Execute query
            $result = $this->queryAll
                ? $this->adapter->queryAll($statement)
                : $this->adapter->query($statement);

            // Track query history
            $this->adapter->queryHistory()->push($statement);

            // Log the query if query logging is enabled
            if ($this->enableQueryLog) {
                $this->logSalesforceError('SOQL Query Executed', [
                    'query' => $statement,
                ], 'info');
            }

            // Collect all records, handling pagination
            $records = $result['records'] ?? [];

            // Handle aggregate queries that return empty records but have totalSize
            // Salesforce returns simple COUNT() results in totalSize instead of records
            // For other aggregates (SUM, AVG, MIN, MAX), empty records means null
            if (empty($records) && isset($result['totalSize']) && $this->isAggregateQuery($statement)) {
                // Only use totalSize for COUNT queries
                if (stripos($statement, 'COUNT(') !== false) {
                    $records = [
                        ['aggregate' => $result['totalSize']],
                    ];
                }
                // For other aggregates, leave records empty so aggregate() returns null
            }

            while (isset($result['nextRecordsUrl'])) {
                $result = $this->adapter->next($result['nextRecordsUrl']);
                if (isset($result['records'])) {
                    $records = array_merge($records, $result['records']);
                }
            }

            // Transform aggregate results from expr0 to aggregate for consistency
            $records = $this->transformAggregateResults($records);

            return $records;
        } catch (Exception $e) {
            // Handle Salesforce exceptions with logging
            $this->handleSalesforceException($e, 'query');

            // If we're not throwing exceptions (based on config), return empty array
            return [];
        }
    }

    /**
     * Run a select statement against the database and returns a generator.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  bool  $useReadPdo
     * @return Generator<int, stdClass>
     *
     * @throws SalesforceException
     * @throws AuthenticationException
     */
    public function cursor($query, $bindings = [], $useReadPdo = true): Generator
    {

        $statement = $this->run($query, $bindings, function ($query, $bindings): array {

            if ($this->pretending()) {
                return [];
            }

            $statement = $this->prepare($query, $bindings);

            if ($this->queryAll) {
                return $this->adapter->queryAll($statement);
            }

            return $this->adapter->query($statement);
        });

        // Yield all records from the initial result
        foreach ($statement['records'] ?? [] as $record) {
            yield $record;
        }

        // Continue fetching paginated results if available
        while (! empty($statement['nextRecordsUrl'])) {
            $statement = $this->adapter->next($statement['nextRecordsUrl']);

            foreach ($statement['records'] ?? [] as $record) {
                yield $record;
            }
        }
    }

    public function prepareBindings(array $bindings): array
    {
        if ($bindings === []) {
            return $bindings;
        }

        $grammar = null;

        foreach ($bindings as $key => $value) {
            // Handle null values explicitly
            if ($value === null) {
                continue;
            }

            // Transform DateTimeInterface instances to SOQL date format
            if ($value instanceof DateTimeInterface) {
                $grammar ??= $this->getQueryGrammar();
                $bindings[$key] = $value->format($grammar->getDateFormat());
                continue;
            }

            // Transform boolean values to SOQL boolean literals
            if (is_bool($value)) {
                $bindings[$key] = $value ? 'TRUE' : 'FALSE';
            }
        }

        return $bindings;
    }

    /**
     * Run a SQL statement and log its execution context.
     *
     * @param  string  $query
     * @param  array  $bindings
     */
    protected function run($query, $bindings, Closure $callback): mixed
    {
        foreach ($this->beforeExecutingCallbacks as $beforeExecutingCallback) {
            $beforeExecutingCallback($query, $bindings, $this);
        }

        $start = microtime(true);

        try {
            $result = $this->runQueryCallback($query, $bindings, $callback);
        } catch (QueryException $e) {
            $result = $this->handleQueryException(
                $e,
                $query,
                $bindings,
                $callback
            );
        }
        // Once we have run the query, we will calculate the time that it took to run and
        // then log the query, bindings, and execution time, so we will report them on
        // the event that the developer needs them. We'll log time in milliseconds.
        $this->logQuery(
            $query,
            $bindings,
            $this->getElapsedTime($start)
        );
        return $result;
    }

    private function prepare(string $query, array $bindings): string
    {
        $bindings = $this->prepareBindings($bindings);

        return Str::replaceArray('?', $bindings, $query);
    }

    /**
     * Transform aggregate results from SOQL aliases (expr0, expr1, etc.) to 'aggregate'
     */
    private function transformAggregateResults(array $records): array
    {
        if ($records === []) {
            return $records;
        }

        // Check if this looks like an aggregate result (has expr0, expr1, etc.)
        // Aggregate results typically have only one record with expr* keys
        return array_map(function ($record) {
            // Handle both array and object formats from Salesforce API
            if (is_array($record) && isset($record['expr0'])) {
                $record['aggregate'] = $record['expr0'];
                unset($record['expr0']);
            } elseif (is_object($record) && isset($record->expr0)) {
                $record->aggregate = $record->expr0;
                unset($record->expr0);
            }
            return $record;
        }, $records);
    }

    /**
     * Check if a query is an aggregate query
     */
    private function isAggregateQuery(string $query): bool
    {
        $query = strtoupper($query);
        return stripos($query, 'COUNT(') !== false ||
               stripos($query, 'SUM(') !== false ||
               stripos($query, 'AVG(') !== false ||
               stripos($query, 'MIN(') !== false ||
               stripos($query, 'MAX(') !== false;
    }
}
