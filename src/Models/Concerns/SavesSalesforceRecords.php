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

        // Filter to only createable fields (includes create-only fields that aren't updateable)
        return $this->filterCreateableFields($attributes);
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
        return $this->filterWriteableFields($attributes, 'updateable');
    }

    /**
     * Filter attributes to only include createable fields.
     * Used during insert operations to preserve fields that are createable but not updateable.
     *
     * @param  array  $attributes  Attributes to filter
     * @return array Filtered attributes containing only createable fields
     */
    protected function filterCreateableFields(array $attributes): array
    {
        return $this->filterWriteableFields($attributes, 'createable');
    }

    /**
     * Strip system fields, then keep only the fields describe marks as createable/updateable.
     * If describe fails, log it and fall back to stripping system fields only.
     *
     * @param  'createable'|'updateable'  $mode
     */
    private function filterWriteableFields(array $attributes, string $mode): array
    {
        if ($attributes === []) {
            return $attributes;
        }

        // Known system fields that are never writeable. CreatedById can be set on
        // insert by orgs with the "Set Audit Fields upon Record Creation" permission.
        $systemFields = ['CreatedDate', 'LastModifiedDate', 'LastModifiedById', 'SystemModstamp', 'IsDeleted'];

        if ($mode === 'updateable') {
            $systemFields[] = 'CreatedById';
        }

        $attributes = array_diff_key($attributes, array_flip($systemFields));

        try {
            $adapter = $this->getSalesforceAdapter();
            $writeableFields = $mode === 'updateable'
                ? $adapter->getUpdateableFields($this->getTable())
                : $adapter->getCreateableFields($this->getTable());

            return array_intersect_key($attributes, array_flip($writeableFields));
        } catch (Throwable $e) {
            $this->logSalesforceError("Failed to get {$mode} fields for filtering: " . $e->getMessage(), [
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
