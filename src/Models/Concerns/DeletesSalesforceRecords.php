<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Models\Concerns;

use Daikazu\EloquentSalesforceObjects\Contracts\AdapterInterface;
use Daikazu\EloquentSalesforceObjects\Exceptions\SalesforceException;
use LogicException;
use Throwable;

trait DeletesSalesforceRecords
{
    use LogsSalesforceErrors;

    /**
     * Delete the model from Salesforce
     *
     * @throws SalesforceException
     * @throws Throwable
     */
    public function delete(): ?bool
    {
        if (empty($this->getKeyName())) {
            throw new LogicException('No primary key defined on model.');
        }

        // If the model doesn't exist, there is nothing to delete
        if (! $this->exists) {
            return false;
        }

        if ($this->fireModelEvent('deleting') === false) {
            return false;
        }

        // Touch owning models before deletion
        $this->touchOwners();

        try {
            $this->performDeleteOnModel();
        } catch (Throwable $e) {
            $this->handleSalesforceException($e, 'delete');

            // If we're not throwing exceptions, return false to indicate failure
            return false;
        }

        // Fire the deleted event
        $this->fireModelEvent('deleted', false);

        return true;
    }

    /**
     * Force delete is the same as regular delete for Salesforce
     * (Salesforce doesn't have soft deletes by default)
     *
     * @throws SalesforceException
     * @throws Throwable
     */
    public function forceDelete(): ?bool
    {
        return $this->delete();
    }

    /**
     * Perform the actual delete operation on Salesforce
     */
    protected function performDeleteOnModel(): void
    {
        $adapter = $this->getSalesforceAdapter();

        $adapter->delete($this->getTable(), $this->getKey());

        $this->exists = false;
    }

    /**
     * Get the Salesforce adapter instance
     */
    abstract protected function getSalesforceAdapter(): AdapterInterface;

    /**
     * Restore is not supported by Salesforce REST API
     *
     * @throws SalesforceException
     */
    public function restore(): never
    {
        throw new SalesforceException('The Salesforce REST API does not natively support UNDELETE');
    }
}
