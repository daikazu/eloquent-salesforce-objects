<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Database;

use Daikazu\EloquentSalesforceObjects\Exceptions\SalesforceException;
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class SalesforceBatch
{
    /** @var array<string, array{type: string, query: string, model: ?string}> */
    private array $queries = [];

    private readonly SalesforceAdapter $adapter;

    private readonly int $batchSize;

    private readonly string $apiVersion;

    private function __construct()
    {
        $this->adapter = app(SalesforceAdapter::class);
        $this->batchSize = min((int) config('eloquent-salesforce-objects.batch_size', 25), 25);
        $this->apiVersion = config('forrest.version', 'v64.0');
    }

    /**
     * Create a new batch instance.
     */
    public static function new(): static
    {
        return new static;
    }

    /**
     * Add a query to the batch.
     *
     * @param  string  $name  Unique name to retrieve results by
     * @param  Builder|SOQLBuilder|string  $query  Eloquent builder or raw SOQL string
     */
    public function add(string $name, Builder | SOQLBuilder | string $query): static
    {
        if (is_string($query)) {
            $this->queries[$name] = [
                'type'  => 'raw',
                'query' => $query,
                'model' => null,
            ];
        } else {
            $model = $query instanceof SOQLBuilder
                ? $query->getModel()::class
                : $query->getModel()::class;

            $this->queries[$name] = [
                'type'  => 'builder',
                'query' => $query->toSql(),
                'model' => $model,
            ];
        }

        return $this;
    }

    /**
     * Execute all queued queries via Salesforce Composite Batch API.
     *
     * Returns a SalesforceBatchResult containing results keyed by the names
     * passed to add(). Each query succeeds or fails independently.
     *
     * @throws SalesforceException
     */
    public function run(): SalesforceBatchResult
    {
        if ($this->queries === []) {
            return new SalesforceBatchResult([]);
        }

        $results = [];
        $queryEntries = collect($this->queries);

        foreach ($queryEntries->chunk($this->batchSize) as $chunk) {
            $batchRequests = $chunk->map(fn (array $entry): array => [
                'method' => 'GET',
                'url'    => $this->apiVersion . '/query?q=' . urlencode($entry['query']),
            ])->values()->toArray();

            try {
                $response = $this->adapter->forrest()->composite('batch', [
                    'method' => 'POST',
                    'body'   => [
                        'batchRequests' => $batchRequests,
                    ],
                ]);

                $index = 0;
                foreach ($chunk as $name => $entry) {
                    $batchResult = $response['results'][$index] ?? null;

                    if (! $batchResult || ($batchResult['statusCode'] ?? 500) !== 200) {
                        $results[$name] = [
                            'success' => false,
                            'error'   => $batchResult['result'][0] ?? $batchResult ?? ['message' => 'Unknown batch error'],
                            'data'    => null,
                        ];
                    } else {
                        $records = $batchResult['result']['records'] ?? [];

                        if ($entry['model']) {
                            $modelClass = $entry['model'];
                            $data = collect($records)->map(fn (array $item) => new $modelClass($item));
                        } else {
                            $data = collect($records)->map(fn (array $item) => (object) $item);
                        }

                        $results[$name] = [
                            'success' => true,
                            'error'   => null,
                            'data'    => $data,
                        ];
                    }

                    $index++;
                }
            } catch (Throwable $e) {
                // If the entire batch HTTP call fails, mark all queries in this chunk as failed
                foreach ($chunk as $name => $entry) {
                    $results[$name] = [
                        'success' => false,
                        'error'   => ['message' => $e->getMessage()],
                        'data'    => null,
                    ];
                }
            }
        }

        return new SalesforceBatchResult($results);
    }
}
