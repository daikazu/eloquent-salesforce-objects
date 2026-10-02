<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Contracts;

use Illuminate\Support\Collection;

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
     * Resolve the columns to select, expanding ['*'] to every field on the object
     *
     * @param  string|object  $object  Salesforce object name or model
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    public function resolveFields(string | object $object, array $columns = ['*']): array;

    /**
     * Get the child relationship name used in parent-to-child subqueries (e.g. "Contacts")
     *
     * @param  string|object  $parent  Parent object name or model
     * @return string|null Null when the relationship doesn't exist or can't be queried
     */
    public function childRelationshipName(string | object $parent, string $childObject, string $field): ?string;

    /**
     * The SOQL statements executed through this adapter
     *
     * @return Collection<int, string>
     */
    public function queryHistory(): Collection;

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
     * Bulk upsert records by an External Id field (sent 200 per request)
     *
     * @param  string  $object  Salesforce object name
     * @param  string  $externalIdField  An External Id field on the object (or "Id")
     * @param  array  $records  Array of record data arrays, each including $externalIdField
     * @param  bool  $allOrNone  If true, each request rolls back entirely if any of its records fails
     * @return array Results with id/success/created/errors for each record
     */
    public function bulkUpsert(string $object, string $externalIdField, array $records, bool $allOrNone = false): array;

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
     * Get the list of updateable field names for a Salesforce object
     *
     * @param  string|object  $object  Salesforce object name or model
     * @return array<int, string>
     */
    public function getUpdateableFields(string | object $object): array;

    /**
     * Get the list of createable field names for a Salesforce object
     *
     * @param  string|object  $object  Salesforce object name or model
     * @return array<int, string>
     */
    public function getCreateableFields(string | object $object): array;

    /**
     * Access the underlying Forrest instance for operations not covered by this adapter
     */
    public function forrest(): mixed;

    /**
     * Call a custom Apex REST endpoint
     *
     * @param  string  $path  The Apex REST path (e.g., '/CreateOrder', 'CreateOrder', or '/CreateOrder/')
     * @param  array  $options  Options array with 'method' (GET|POST|PATCH|DELETE|PUT) and optional 'body' and 'parameters'
     * @return array Response data
     */
    public function apexRest(string $path, array $options = []): array;
}
