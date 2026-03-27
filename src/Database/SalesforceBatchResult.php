<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Database;

use Illuminate\Support\Collection;

class SalesforceBatchResult
{
    /** @var array<string, array{success: bool, error: ?array, data: ?Collection}> */
    private array $results;

    public function __construct(array $results)
    {
        $this->results = $results;
    }

    /**
     * Get the results for a named query.
     * Returns a Collection of model instances (for builder queries) or stdClass objects (for raw SOQL).
     * Returns null if the query failed.
     */
    public function get(string $name): ?Collection
    {
        return $this->results[$name]['data'] ?? null;
    }

    /**
     * Check if a named query failed.
     */
    public function failed(string $name): bool
    {
        if (! isset($this->results[$name])) {
            return true;
        }

        return ! $this->results[$name]['success'];
    }

    /**
     * Check if a named query succeeded.
     */
    public function successful(string $name): bool
    {
        return ! $this->failed($name);
    }

    /**
     * Get the error details for a failed query.
     * Returns null if the query succeeded or doesn't exist.
     */
    public function error(string $name): ?array
    {
        return $this->results[$name]['error'] ?? null;
    }

    /**
     * Get all query names.
     *
     * @return array<string>
     */
    public function names(): array
    {
        return array_keys($this->results);
    }

    /**
     * Check if all queries succeeded.
     */
    public function allSuccessful(): bool
    {
        foreach ($this->results as $result) {
            if (! $result['success']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get all failed query names.
     *
     * @return array<string>
     */
    public function failures(): array
    {
        return array_keys(array_filter($this->results, fn (array $r): bool => ! $r['success']));
    }
}
