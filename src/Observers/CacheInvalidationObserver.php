<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Observers;

use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;
use Daikazu\EloquentSalesforceObjects\Support\QueryCache;
use Illuminate\Support\Facades\Log;

/**
 * Observer to automatically invalidate cache when Salesforce records change
 *
 * Listens to model created/updated/deleted events and invalidates cache accordingly.
 * This ensures the cache stays fresh when the local application makes changes.
 */
class CacheInvalidationObserver
{
    protected QueryCache $queryCache;
    protected bool $enabled;
    protected bool $verbose;

    public function __construct()
    {
        $this->queryCache = new QueryCache;
        $this->enabled = config('eloquent-salesforce-objects.query_cache.auto_invalidate_on_local_changes', true);
        $this->verbose = config('eloquent-salesforce-objects.enable_query_log', false);
    }

    /**
     * Handle the model "created" event.
     *
     * When a new record is created, invalidate cache based on configured strategy.
     */
    public function created(SalesforceModel $model): void
    {
        if (! $this->enabled) {
            return;
        }

        $objectName = $model->getTable();
        $recordId = $model->getAttribute('Id');

        // For new records, we flush the entire object since we don't know
        // which queries would match this new record
        $this->queryCache->flushObject($objectName);

        if ($this->verbose) {
            Log::info("Cache invalidated after creating {$objectName}", [
                'object'            => $objectName,
                'record_id'         => $recordId,
                'invalidation_type' => 'object-level (new record)',
            ]);
        }
    }

    /**
     * Handle the model "updated" event.
     *
     * When a record is updated, invalidate cache based on configured strategy.
     */
    public function updated(SalesforceModel $model): void
    {
        if (! $this->enabled) {
            return;
        }

        $objectName = $model->getTable();
        $recordId = $model->getAttribute('Id');
        $strategy = $this->queryCache->getInvalidationStrategy();

        if ($strategy === 'record' && $recordId) {
            // Surgical invalidation - only invalidate queries containing this record
            $this->queryCache->invalidateByRecordIds($objectName, [$recordId]);
            $invalidationType = 'record-level';
        } else {
            // Object-level invalidation
            $this->queryCache->flushObject($objectName);
            $invalidationType = 'object-level';
        }

        if ($this->verbose) {
            Log::info("Cache invalidated after updating {$objectName}", [
                'object'             => $objectName,
                'record_id'          => $recordId,
                'changed_attributes' => array_keys($model->getDirty()),
                'invalidation_type'  => $invalidationType,
            ]);
        }
    }

    /**
     * Handle the model "deleted" event.
     *
     * When a record is deleted, invalidate cache based on configured strategy.
     */
    public function deleted(SalesforceModel $model): void
    {
        if (! $this->enabled) {
            return;
        }

        $objectName = $model->getTable();
        $recordId = $model->getAttribute('Id');
        $strategy = $this->queryCache->getInvalidationStrategy();

        if ($strategy === 'record' && $recordId) {
            // Surgical invalidation
            $this->queryCache->invalidateByRecordIds($objectName, [$recordId]);
            $invalidationType = 'record-level';
        } else {
            // Object-level invalidation
            $this->queryCache->flushObject($objectName);
            $invalidationType = 'object-level';
        }

        if ($this->verbose) {
            Log::info("Cache invalidated after deleting {$objectName}", [
                'object'            => $objectName,
                'record_id'         => $recordId,
                'invalidation_type' => $invalidationType,
            ]);
        }
    }

    /**
     * Handle the model "restored" event (for soft deletes).
     *
     * When a record is restored, invalidate cache based on configured strategy.
     */
    public function restored(SalesforceModel $model): void
    {
        if (! $this->enabled) {
            return;
        }

        $objectName = $model->getTable();
        $recordId = $model->getAttribute('Id');
        $strategy = $this->queryCache->getInvalidationStrategy();

        if ($strategy === 'record' && $recordId) {
            // Surgical invalidation
            $this->queryCache->invalidateByRecordIds($objectName, [$recordId]);
            $invalidationType = 'record-level';
        } else {
            // Object-level invalidation
            $this->queryCache->flushObject($objectName);
            $invalidationType = 'object-level';
        }

        if ($this->verbose) {
            Log::info("Cache invalidated after restoring {$objectName}", [
                'object'            => $objectName,
                'record_id'         => $recordId,
                'invalidation_type' => $invalidationType,
            ]);
        }
    }

    /**
     * Handle the model "force deleted" event.
     *
     * When a record is force deleted, invalidate cache based on configured strategy.
     */
    public function forceDeleted(SalesforceModel $model): void
    {
        if (! $this->enabled) {
            return;
        }

        $objectName = $model->getTable();
        $recordId = $model->getAttribute('Id');
        $strategy = $this->queryCache->getInvalidationStrategy();

        if ($strategy === 'record' && $recordId) {
            // Surgical invalidation
            $this->queryCache->invalidateByRecordIds($objectName, [$recordId]);
            $invalidationType = 'record-level';
        } else {
            // Object-level invalidation
            $this->queryCache->flushObject($objectName);
            $invalidationType = 'object-level';
        }

        if ($this->verbose) {
            Log::info("Cache invalidated after force deleting {$objectName}", [
                'object'            => $objectName,
                'record_id'         => $recordId,
                'invalidation_type' => $invalidationType,
            ]);
        }
    }
}
