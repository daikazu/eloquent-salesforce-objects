<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Database;

use Closure;
use Daikazu\EloquentSalesforceObjects\Contracts\AdapterInterface;
use Daikazu\EloquentSalesforceObjects\Exceptions\AuthenticationException;
use Daikazu\EloquentSalesforceObjects\Exceptions\SalesforceException;
use Daikazu\EloquentSalesforceObjects\Models\Concerns\LogsSalesforceErrors;
use DateTimeInterface;
use Exception;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use stdClass;

class SOQLConnection extends Connection
{
    use LogsSalesforceErrors;

    protected bool $enableQueryLog;

    public function __construct(
        private readonly AdapterInterface $adapter,
        private readonly bool $queryAll = false,
    ) {
        // Cache config values for performance
        $this->enableQueryLog = config('eloquent-salesforce-objects.enable_query_log', false);
    }

    public function getAdapter(): AdapterInterface
    {
        return $this->adapter;
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
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        return $this->run($query, $bindings, function (string $query, array $bindings): array {
            $statement = $this->substituteBindings($query, $bindings);
            return $this->executeQuery($statement);
        });
    }

    /**
     * Execute the actual Salesforce query
     *
     * @param  string  $statement  Prepared SOQL statement
     * @return array Query results
     */
    protected function executeQuery(string $statement): array
    {
        try {
            $result = $this->fetch($statement);

            // Collect all records, handling pagination
            $records = $result['records'] ?? [];

            // COUNT() returns its result in totalSize with no records. Other aggregates
            // (SUM, AVG, MIN, MAX) with no records mean null, so leave them empty.
            if (empty($records) && isset($result['totalSize']) && $this->isCountQuery($statement)) {
                $records = [
                    ['aggregate' => $result['totalSize']],
                ];
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
     * Send a prepared statement to Salesforce and record it in the query history.
     */
    private function fetch(string $statement): array
    {
        $result = $this->queryAll
            ? $this->adapter->queryAll($statement)
            : $this->adapter->query($statement);

        $this->adapter->queryHistory()->push($statement);

        if ($this->enableQueryLog) {
            $this->logSalesforceError('SOQL Query Executed', [
                'query' => $statement,
            ], 'info');
        }

        return $result;
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
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): Generator
    {

        $statement = $this->run($query, $bindings, function (string $query, array $bindings): array {
            if ($this->pretending()) {
                return [];
            }

            try {
                return $this->fetch($this->substituteBindings($query, $bindings));
            } catch (Exception $e) {
                $this->handleSalesforceException($e, 'query');

                return [];
            }
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

                continue;
            }

            // Escape string values to prevent SOQL injection. Backslashes must be
            // escaped first, or a trailing "\" would swallow the closing quote.
            if (is_string($value)) {
                $bindings[$key] = self::escapeSoqlString($value);
            }
        }

        return $bindings;
    }

    /**
     * Escape a string for use inside a quoted SOQL literal.
     *
     * O'Brien -> O\'Brien, C:\path -> C:\\path, newlines -> \n
     */
    public static function escapeSoqlString(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            "'"  => "\\'",
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        ]);
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

        // Unlike Laravel's run(), don't wrap failures in QueryException: let the
        // SalesforceException through, with its own message and status/error code.
        $result = $callback($query, $bindings);
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

    /**
     * Replace the ? placeholders in a compiled query with escaped binding values.
     */
    public function substituteBindings(string $query, array $bindings): string
    {
        // SOQL's null literal, e.g. whereIn('Name', ['a', null]) -> Name in ('a', null)
        $bindings = array_map(fn ($value) => $value ?? 'null', $this->prepareBindings($bindings));

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
     * Check if a query is a COUNT aggregate, as compiled by SOQLGrammar::compileAggregate().
     *
     * Only the start of the statement is checked, so values in the WHERE clause
     * that happen to contain "COUNT(" are not mistaken for an aggregate.
     */
    private function isCountQuery(string $query): bool
    {
        return preg_match('/^\s*select\s+count\(/i', $query) === 1;
    }
}
