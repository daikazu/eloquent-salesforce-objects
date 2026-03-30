<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Models\Concerns;

use Daikazu\EloquentSalesforceObjects\Contracts\AdapterInterface;

trait HasSalesforceMetadata
{
    /**
     * Get picklist values for a specific field on this model
     *
     * Can be called statically or on an instance:
     *   Account::picklistValues('Industry')
     *   $account->picklistValues('Industry')
     *
     * @param  string  $field  Field name
     * @return array Array of picklist values with 'value' and 'label' keys
     */
    public static function picklistValues(string $field): array
    {
        $instance = new static;
        $adapter = $instance->getSalesforceAdapter();

        return $adapter->picklistValues($instance->getTable(), $field);
    }

    /**
     * Get the describe metadata for this model's Salesforce object
     *
     * Can be called statically or on an instance:
     *   Account::describe()
     *   $account->describe()
     */
    public static function describe(): array
    {
        $instance = new static;
        $adapter = $instance->getSalesforceAdapter();

        return $adapter->describe($instance->getTable());
    }

    /**
     * Get metadata for a specific field
     *
     * Can be called statically or on an instance:
     *   Account::fieldMetadata('Name')
     *   $account->fieldMetadata('Name')
     *
     * @param  string  $field  Field name
     * @return array|null Field metadata or null if not found
     */
    public static function fieldMetadata(string $field): ?array
    {
        $metadata = static::describe();

        return collect($metadata['fields'] ?? [])->firstWhere('name', $field);
    }

    /**
     * Get the Salesforce adapter instance
     */
    abstract protected function getSalesforceAdapter(): AdapterInterface;
}
