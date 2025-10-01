<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Database;

use Daikazu\EloquentSalesforceObjects\Models\Concerns\LogsSalesforceErrors;
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;
use Exception;
use Illuminate\Support\Collection;
use Throwable;

class SOQLBatch extends Collection
{
    use LogsSalesforceErrors;

    protected int $batchSize;
    protected bool $enableQueryLog;
    protected bool $throwExceptions;
    protected string $apiVersion;

    public function __construct(
        private readonly SalesforceAdapter $adapter,
        array $items = []
    ) {
        parent::__construct($items);

        // Cache config values for performance
        $this->batchSize = config('eloquent-salesforce-objects.batch_size', 25);
        $this->enableQueryLog = config('eloquent-salesforce-objects.enable_query_log', false);
        $this->throwExceptions = config('eloquent-salesforce-objects.throw_exceptions', true);
        $this->apiVersion = config('forrest.version', 'v64.0');
    }

    public function results($key)
    {
        return $this->get($key);
    }

    public function builder($key)
    {
        return parent::get($key, $this->emptyItem())->builder;
    }

    public function class($key)
    {
        return parent::get($key, $this->emptyItem())->class;
    }

    public function get($key, $default = null)
    {
        return parent::get($key, $this->emptyItem())->results;
    }

    private function emptyItem(): object
    {
        return (object) [
            'class'   => null,
            'builder' => null,
            'results' => collect(),
        ];
    }

    //    public function query(...$builders): HigherOrderTapProxy|SOQLBatch|static
    //    {
    //        $tempColl = null;
    //        foreach ($builders as $builder) {
    //            $tempColl = tap($this, function($collection) use ($builder) {
    //                $collection->batch($builder);
    //            });
    //        }
    //        return $tempColl ?: $this;
    //    }

    public function query(...$builders): static
    {
        foreach ($builders as $builder) {
            $this->batch($builder);
        }

        return $this;
    }

    public function batch(SOQLBuilder $builder, ?string $tag = null): SOQLBuilder
    {
        $tag ??= class_basename($builder->getModel()) . '_' . $this->count();

        parent::put($tag, (object) [
            'class'   => $builder->getModel()::class,
            'builder' => $builder,
            'results' => collect(),
        ]);

        return $builder;
    }

    /**
     * Execute all batched queries
     *
     * @return $this
     *
     * @throws Throwable
     */
    public function run(): static
    {
        if ($this->isEmpty()) {
            return $this;
        }

        // Salesforce batch API limit is 25 queries per batch
        if ($this->batchSize > 25) {
            $this->logSalesforceError(
                'Salesforce will only allow select batches of 25 queries.',
                ['requested_size' => $this->batchSize],
                'warning'
            );
            $this->batchSize = 25;
        }

        foreach ($this->chunk($this->batchSize) as $chunk) {
            // Build batch request payload
            $batchRequests = $chunk->map(fn($query): array => [
                'method' => 'GET',
                'url'    => $this->apiVersion . '/query?q=' . urlencode((string) $query->builder->toSql()),
            ])->values()->toArray();

            // Log the batch query
            if ($this->enableQueryLog) {
                $this->logSalesforceError('SOQL Batch Query', ['requests' => $batchRequests], 'info');
            }

            try {
                // Execute batch request via Forrest
                $results = $this->adapter->forrest()->composite('batch', [
                    'method' => 'POST',
                    'body'   => [
                        'batchRequests' => $batchRequests,
                    ],
                ]);

                // Process results
                $index = 0;
                foreach ($chunk as $key => $batch) {
                    $batchResult = $results['results'][$index];

                    if ($batchResult['statusCode'] != 200) {
                        $batch->results = (object) $batchResult;
                    } else {
                        $batch->results = collect($batchResult['result']['records'])->map(function ($item) use ($batch): object {
                            $model = $batch->class;

                            return new $model($item);
                        });
                    }

                    $this->put($key, $batch);
                    $index++;
                }
            } catch (Exception $e) {
                $this->handleSalesforceException($e, 'batch');

                // If not throwing, continue to next chunk
                if (! $this->throwExceptions) {
                    continue;
                }
            }
        }

        return $this;
    }
}
