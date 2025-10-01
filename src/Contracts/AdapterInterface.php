<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Contracts;

interface AdapterInterface
{
    /**
     * Execute a SOQL query
     */
    public function query(string $soql): array;

    /**
     * Execute a SOQL query and include deleted records
     */
    public function queryAll(string $soql): array;

    /**
     * Get next batch of records from a previous query
     */
    public function next(string $nextRecordsUrl): array;

    /**
     * Execute a SOSL search
     */
    public function search(string $sosl): array;

    /**
     * Describe global Salesforce objects
     */
    public function describeGlobal(): array;

    /**
     * Describe a specific Salesforce object
     *
     * @param  string|object|null  $object  Salesforce object name string, SalesforceModel class string (Account::class), SalesforceModel instance, or null
     */
    public function describe(string | object | null $object = null): array;

    /**
     * Retrieve a record by ID
     */
    public function retrieve(string $object, string $id, ?array $fields = null): array;

    /**
     * Create a new record
     */
    public function create(string $object, array $data): array;

    /**
     * Update an existing record
     */
    public function update(string $object, string $id, array $data): bool;

    /**
     * Delete a record
     */
    public function delete(string $object, string $id): bool;

    /**
     * Upsert a record using an external ID field
     */
    public function upsert(string $object, string $externalIdField, string $externalId, array $data): array;

    /**
     * Get the Salesforce instance URL
     */
    public function getInstanceUrl(): string;

    /**
     * Get picklist values for a specific field
     *
     * @param  string|object  $object  Salesforce object name string, SalesforceModel class string (Account::class), or SalesforceModel instance
     * @param  string  $field  Field name
     * @return array Array of picklist values
     */
    public function picklistValues(string | object $object, string $field): array;

    /**
     * Bulk create multiple records (up to 200 per request)
     *
     * @param  string  $object  Salesforce object name
     * @param  array  $records  Array of record data arrays
     * @param  bool  $allOrNone  If true, entire operation rolls back on any error
     * @return array Results with success/error info for each record
     */
    public function bulkCreate(string $object, array $records, bool $allOrNone = false): array;

    /**
     * Bulk update multiple records (up to 200 per request)
     *
     * @param  string  $object  Salesforce object name
     * @param  array  $records  Array of record data arrays (must include 'Id' field)
     * @param  bool  $allOrNone  If true, entire operation rolls back on any error
     * @return array Results with success/error info for each record
     */
    public function bulkUpdate(string $object, array $records, bool $allOrNone = false): array;

    /**
     * Bulk delete multiple records (up to 200 per request)
     *
     * @param  string  $object  Salesforce object name
     * @param  array  $ids  Array of record IDs to delete
     * @param  bool  $allOrNone  If true, entire operation rolls back on any error
     * @return array Results with success/error info for each record
     */
    public function bulkDelete(string $object, array $ids, bool $allOrNone = false): array;

    /**
     * Access the underlying Forrest instance for operations not covered by this adapter
     */
    public function forrest(): mixed;
}
