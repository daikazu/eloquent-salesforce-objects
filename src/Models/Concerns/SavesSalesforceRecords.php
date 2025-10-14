<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Models\Concerns;

use Daikazu\EloquentSalesforceObjects\Contracts\AdapterInterface;
use Daikazu\EloquentSalesforceObjects\Exceptions\SalesforceException;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

trait SavesSalesforceRecords
{
    use LogsSalesforceErrors;

    /**
     * Perform a model insert operation on Salesforce
     *
     * @throws SalesforceException
     * @throws Throwable
     */
    protected function performInsert(Builder $query): bool
    {
        if ($this->fireModelEvent('creating') === false) {
            return false;
        }

        // Get writable attributes (excluding read-only fields)
        $attributes = $this->getAttributesForInsert();

        $adapter = $this->getSalesforceAdapter();

        try {
            // Create the record in Salesforce
            $response = $adapter->create($this->getTable(), $attributes);

            // Salesforce returns the new record's ID
            if (isset($response['id'])) {
                $this->setAttribute($this->getKeyName(), $response['id']);
            }

            $this->exists = true;
            $this->wasRecentlyCreated = true;

            $this->fireModelEvent('created', false);

            return true;
        } catch (Throwable $e) {
            $this->handleSalesforceException($e, 'create');

            return false;
        }
    }

    /**
     * Perform a model update operation on Salesforce
     *
     * @throws SalesforceException
     * @throws Throwable
     */
    protected function performUpdate(Builder $query): bool
    {
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        // Get only the dirty attributes
        $dirty = $this->getDirtyForUpdate();

        if (empty($dirty)) {
            return true;
        }

        $adapter = $this->getSalesforceAdapter();

        try {
            // Update the record in Salesforce
            $adapter->update($this->getTable(), $this->getKey(), $dirty);

            $this->syncChanges();

            $this->fireModelEvent('updated', false);

            return true;
        } catch (Throwable $e) {
            $this->handleSalesforceException($e, 'update');

            return false;
        }
    }

    /**
     * Get the attributes that should be used for insert
     */
    protected function getAttributesForInsert(): array
    {
        $attributes = $this->getAttributes();

        // Remove primary key and metadata
        unset($attributes[$this->getKeyName()]);
        unset($attributes['attributes']); // Remove Salesforce metadata attribute

        // Filter out non-createable/non-updateable fields
        return $this->filterUpdateableFields($attributes);
    }

    /**
     * Get the dirty attributes for update
     */
    protected function getDirtyForUpdate(): array
    {
        $dirty = $this->getDirty();

        // Remove primary key and metadata
        unset($dirty[$this->getKeyName()]);
        unset($dirty['attributes']);

        // Filter out non-updateable fields
        return $this->filterUpdateableFields($dirty);
    }

    /**
     * Filter attributes to only include updateable fields
     * Removes read-only fields like CreatedDate, SystemModstamp, formula fields, etc.
     *
     * @param  array  $attributes  Attributes to filter
     * @return array Filtered attributes containing only updateable fields
     */
    protected function filterUpdateableFields(array $attributes): array
    {
        if ($attributes === []) {
            return $attributes;
        }

        // Always exclude known system fields that are never updateable
        $systemFields = [
            'CreatedDate',
            'CreatedById',
            'LastModifiedDate',
            'LastModifiedById',
            'SystemModstamp',
            'IsDeleted',
        ];

        // Remove system fields first
        $attributes = array_diff_key($attributes, array_flip($systemFields));

        // Get updateable fields from Salesforce metadata (cached for performance)
        try {
            $updateableFields = $this->getSalesforceAdapter()
                ->getUpdateableFields($this->getTable());

            // Keep only fields that are in the updateable list
            return array_filter($attributes, fn ($value, $key): bool => in_array($key, $updateableFields), ARRAY_FILTER_USE_BOTH);
        } catch (Throwable $e) {
            // If we can't get updateable fields (e.g., API error), log and return filtered by system fields only
            // This provides basic protection even if describe call fails
            $this->logSalesforceError('Failed to get updateable fields for filtering: ' . $e->getMessage(), [
                'exception' => $e::class,
                'object'    => $this->getTable(),
            ], 'warning');

            return $attributes;
        }
    }

    /**
     * Get the Salesforce adapter instance
     */
    abstract protected function getSalesforceAdapter(): AdapterInterface;
}
