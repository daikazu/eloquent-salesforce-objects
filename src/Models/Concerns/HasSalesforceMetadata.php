<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Models\Concerns;

use Daikazu\EloquentSalesforceObjects\Contracts\AdapterInterface;

trait HasSalesforceMetadata
{
    /**
     * Get picklist values for a specific field on this model
     *
     * @param  string  $field  Field name
     * @return array Array of picklist values with 'value' and 'label' keys
     */
    public function picklistValues(string $field): array
    {
        $adapter = $this->getSalesforceAdapter();

        return $adapter->picklistValues($this->getTable(), $field);
    }

    /**
     * Get the describe metadata for this model's Salesforce object
     */
    public function describe(): array
    {
        $adapter = $this->getSalesforceAdapter();

        return $adapter->describe($this->getTable());
    }

    /**
     * Get metadata for a specific field
     *
     * @param  string  $field  Field name
     * @return array|null Field metadata or null if not found
     */
    public function fieldMetadata(string $field): ?array
    {
        $metadata = $this->describe();

        return collect($metadata['fields'] ?? [])->firstWhere('name', $field);
    }

    /**
     * Get the Salesforce adapter instance
     */
    abstract protected function getSalesforceAdapter(): AdapterInterface;
}
