<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Support;

use Daikazu\EloquentSalesforceObjects\Contracts\AdapterInterface;
use Daikazu\EloquentSalesforceObjects\Exceptions\AuthenticationException;
use Daikazu\EloquentSalesforceObjects\Exceptions\SalesforceException;
use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;
use ReflectionClass;
use ReflectionException;
use Throwable;

/**
 * Salesforce API adapter implementation using omniphx/forrest
 */
class SalesforceAdapter implements AdapterInterface
{
    protected ResponseParser $parser;
    protected int $metadataCacheTtl;
    protected string $apiVersion;
    protected int $bulkOperationSize;
    protected array $noSoftDeletes;

    public function __construct(
        protected ?AuthenticationManager $authManager = null,
        private readonly Collection $queryHistory = new Collection,
    ) {
        $this->parser = new ResponseParser;
        $this->authManager = $authManager ?? new AuthenticationManager;

        // Cache config values for performance
        $this->metadataCacheTtl = config('eloquent-salesforce-objects.metadata_cache_ttl', 86400);
        $this->apiVersion = config('forrest.version', 'v64.0');
        $this->bulkOperationSize = config('eloquent-salesforce-objects.bulk_operation_size', 200);
        $this->noSoftDeletes = config('eloquent-salesforce-objects.no_soft_deletes', ['User']);
    }

    public function queryHistory(): Collection
    {
        return $this->queryHistory;
    }

    /**
     * @throws SalesforceException
     * @throws AuthenticationException
     */
    public function query(string $soql): array
    {
        $this->ensureAuthenticated();

        try {
            $response = Forrest::query($soql);

            return $this->parser->parseQueryResponse($response);
        } catch (Throwable $e) {
            throw new SalesforceException('Query failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws SalesforceException
     * @throws AuthenticationException
     */
    public function queryAll(string $soql): array
    {
        $this->ensureAuthenticated();

        try {
            $response = Forrest::queryAll($soql);

            return $this->parser->parseQueryResponse($response);
        } catch (Throwable $e) {
            throw new SalesforceException('QueryAll failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws SalesforceException
     * @throws AuthenticationException
     */
    public function next(string $nextRecordsUrl): array
    {
        $this->ensureAuthenticated();

        try {
            $response = Forrest::next($nextRecordsUrl);

            return $this->parser->parseQueryResponse($response);
        } catch (Throwable $e) {
            throw new SalesforceException('Next records query failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws SalesforceException
     * @throws AuthenticationException
     */
    public function search(string $sosl): array
    {
        $this->ensureAuthenticated();

        try {
            $response = Forrest::search($sosl);

            return $this->parser->parseQueryResponse($response);
        } catch (Throwable $e) {
            throw new SalesforceException('Search failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws SalesforceException
     * @throws AuthenticationException
     */
    public function retrieve(string $object, string $id, ?array $fields = null): array
    {
        $this->ensureAuthenticated();

        try {
            $path = "sobjects/{$object}/{$id}";

            if ($fields !== null && count($fields) > 0) {
                $path .= '?fields=' . implode(',', $fields);
            }

            $response = Forrest::get($path);

            return $this->parser->parseRecordResponse($response);
        } catch (Throwable $e) {
            throw new SalesforceException("Retrieve failed for {$object} {$id}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws SalesforceException
     * @throws AuthenticationException
     */
    public function create(string $object, array $data): array
    {
        $this->ensureAuthenticated();

        try {
            // Reverse map field names before sending to Salesforce
            $mappedData = $this->parser->reverseMapFields($data);

            $response = Forrest::sobjects($object, [
                'method' => 'post',
                'body'   => $mappedData,
            ]);

            return $this->parser->parseCreateResponse($response);
        } catch (Throwable $e) {
            throw new SalesforceException("Create failed for {$object}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws SalesforceException
     * @throws AuthenticationException
     */
    public function update(string $object, string $id, array $data): bool
    {
        $this->ensureAuthenticated();

        try {
            // Reverse map field names before sending to Salesforce
            $mappedData = $this->parser->reverseMapFields($data);

            Forrest::sobjects("{$object}/{$id}", [
                'method' => 'patch',
                'body'   => $mappedData,
            ]);

            return true;
        } catch (Throwable $e) {
            throw new SalesforceException("Update failed for {$object} {$id}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws SalesforceException
     * @throws AuthenticationException
     */
    public function delete(string $object, string $id): bool
    {
        $this->ensureAuthenticated();

        try {
            Forrest::sobjects("{$object}/{$id}", [
                'method' => 'delete',
            ]);

            return true;
        } catch (Throwable $e) {
            throw new SalesforceException("Delete failed for {$object} {$id}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws SalesforceException
     * @throws AuthenticationException
     */
    public function upsert(string $object, string $externalIdField, string $externalId, array $data): array
    {
        $this->ensureAuthenticated();

        try {
            // Reverse map field names before sending to Salesforce
            $mappedData = $this->parser->reverseMapFields($data);

            $response = Forrest::sobjects("{$object}/{$externalIdField}/{$externalId}", [
                'method' => 'patch',
                'body'   => $mappedData,
            ]);

            return $this->parser->parseCreateResponse($response);
        } catch (Throwable $e) {
            throw new SalesforceException("Upsert failed for {$object}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Bulk create multiple records using Salesforce Composite SObject Collections API
     * Can handle up to 200 records per request
     *
     * @param  string  $object  Salesforce object name
     * @param  array  $records  Array of record data arrays
     * @param  bool  $allOrNone  If true, entire operation rolls back on any error
     * @return array Results with success/error info for each record
     *
     * @throws SalesforceException
     * @throws AuthenticationException
     */
    public function bulkCreate(string $object, array $records, bool $allOrNone = false): array
    {
        $this->ensureAuthenticated();

        if ($records === []) {
            return [];
        }

        // Salesforce limit is 200 records per request
        if (count($records) > $this->bulkOperationSize) {
            throw new SalesforceException("Bulk create is limited to {$this->bulkOperationSize} records per request. Got " . count($records) . ' records.');
        }

        try {
            // Map field names for all records
            $mappedRecords = array_map(
                fn ($record): array => array_merge(['attributes' => ['type' => $object]], $this->parser->reverseMapFields($record)),
                $records
            );

            return Forrest::post("{$this->apiVersion}/composite/sobjects", [
                'body' => [
                    'allOrNone' => $allOrNone,
                    'records'   => $mappedRecords,
                ],
            ]);
        } catch (Throwable $e) {
            throw new SalesforceException("Bulk create failed for {$object}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Bulk update multiple records using Salesforce Composite SObject Collections API
     * Can handle up to 200 records per request
     *
     * @param  string  $object  Salesforce object name
     * @param  array  $records  Array of record data arrays (must include 'Id' field)
     * @param  bool  $allOrNone  If true, entire operation rolls back on any error
     * @return array Results with success/error info for each record
     *
     * @throws SalesforceException
     * @throws AuthenticationException
     */
    public function bulkUpdate(string $object, array $records, bool $allOrNone = false): array
    {
        $this->ensureAuthenticated();

        if ($records === []) {
            return [];
        }

        // Salesforce limit is 200 records per request
        if (count($records) > $this->bulkOperationSize) {
            throw new SalesforceException("Bulk update is limited to {$this->bulkOperationSize} records per request. Got " . count($records) . ' records.');
        }

        try {
            // Map field names for all records and ensure attributes are set
            $mappedRecords = array_map(
                fn ($record): array => array_merge(['attributes' => ['type' => $object]], $this->parser->reverseMapFields($record)),
                $records
            );

            return Forrest::patch("{$this->apiVersion}/composite/sobjects", [
                'body' => [
                    'allOrNone' => $allOrNone,
                    'records'   => $mappedRecords,
                ],
            ]);
        } catch (Throwable $e) {
            throw new SalesforceException("Bulk update failed for {$object}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Bulk delete multiple records using Salesforce Composite SObject Collections API
     * Can handle up to 200 records per request
     *
     * @param  string  $object  Salesforce object name (not used by API but kept for consistency)
     * @param  array  $ids  Array of record IDs to delete
     * @param  bool  $allOrNone  If true, entire operation rolls back on any error
     * @return array Results with success/error info for each record
     *
     * @throws SalesforceException
     * @throws AuthenticationException
     */
    public function bulkDelete(string $object, array $ids, bool $allOrNone = false): array
    {
        $this->ensureAuthenticated();

        if ($ids === []) {
            return [];
        }

        // Salesforce limit is 200 records per request
        if (count($ids) > $this->bulkOperationSize) {
            throw new SalesforceException("Bulk delete is limited to {$this->bulkOperationSize} records per request. Got " . count($ids) . ' records.');
        }

        try {
            $idsParam = implode(',', $ids);

            return Forrest::delete("{$this->apiVersion}/composite/sobjects?ids={$idsParam}&allOrNone=" . ($allOrNone ? 'true' : 'false'));
        } catch (Throwable $e) {
            throw new SalesforceException("Bulk delete failed for {$object}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws SalesforceException
     * @throws AuthenticationException
     */
    public function describeGlobal(): array
    {
        $this->ensureAuthenticated();

        try {
            $response = Forrest::describe();

            return $this->parser->parseMetadataResponse($response);
        } catch (Throwable $e) {
            throw new SalesforceException('DescribeGlobal failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param  string|object|null  $object  Salesforce object name, SalesforceModel class/instance, or null
     *
     * @throws SalesforceException
     * @throws AuthenticationException
     */
    public function describe(string | object | null $object = null): array
    {
        $this->ensureAuthenticated();

        $objectName = $this->resolveObjectName($object);

        // Use metadata cache if enabled
        if ($this->metadataCacheTtl > 0 && $objectName) {
            $cacheKey = "salesforce_describe_{$objectName}";

            return Cache::remember($cacheKey, $this->metadataCacheTtl, fn(): array => $this->performDescribe($objectName));
        }

        return $this->performDescribe($objectName);
    }

    /**
     * Perform the actual describe API call
     *
     *
     * @throws SalesforceException
     */
    protected function performDescribe(?string $objectName): array
    {
        try {
            $response = Forrest::describe($objectName);

            return $this->parser->parseMetadataResponse($response);
        } catch (Throwable $e) {
            $message = $objectName !== null && $objectName !== '' && $objectName !== '0' ? "Describe failed for {$objectName}: " : 'Describe failed: ';
            throw new SalesforceException($message . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws AuthenticationException
     */
    public function getInstanceUrl(): string
    {
        return $this->authManager->getInstanceUrl();
    }

    /**
     * Get picklist values for a specific field
     *
     * @param  string|object  $object  Salesforce object name string, SalesforceModel class string (Account::class), or SalesforceModel instance
     * @param  string  $field  Field name
     * @return array Array of picklist values with 'value' and 'label' keys
     *
     * @throws SalesforceException
     * @throws AuthenticationException
     */
    public function picklistValues(string | object $object, string $field): array
    {
        // Resolve the object name for error messages and metadata lookup
        $objectName = $this->resolveObjectName($object);

        // Get metadata
        $metadata = $this->describe($object);

        // Find the field in the metadata
        $fieldMetadata = collect($metadata['fields'] ?? [])->firstWhere('name', $field);

        if (! $fieldMetadata) {
            throw new SalesforceException("Field '{$field}' not found on object '{$objectName}'");
        }

        // Check if field is a picklist type
        $picklistTypes = ['picklist', 'multipicklist'];
        if (! in_array($fieldMetadata['type'] ?? '', $picklistTypes)) {
            throw new SalesforceException("Field '{$field}' on object '{$objectName}' is not a picklist field");
        }

        // Extract picklist values
        $picklistValues = [];
        foreach ($fieldMetadata['picklistValues'] ?? [] as $value) {
            if ($value['active'] ?? false) {
                $picklistValues[] = [
                    'value'        => $value['value'],
                    'label'        => $value['label'],
                    'defaultValue' => $value['defaultValue'] ?? false,
                ];
            }
        }

        return $picklistValues;
    }

    /**
     * Resolve field columns for a Salesforce object
     * Expands '*' wildcard to actual field names from describe metadata
     *
     * @param  string|object  $object  Salesforce object name string, SalesforceModel class string, or SalesforceModel instance
     * @param  array  $columns  Array of column names or ['*'] for all fields
     * @return array Array of resolved field names
     *
     * @throws SalesforceException
     * @throws AuthenticationException
     */
    public function resolveFields(string | object $object, array $columns = ['*']): array
    {
        // If specific columns requested, return as-is
        if ($columns !== ['*']) {
            return $columns;
        }

        $objectName = $this->resolveObjectName($object);

        // Use describe to get all updatable fields
        $metadata = $this->describe($object);

        // Start with standard fields
        $resolvedColumns = ['Id', 'CreatedDate', 'LastModifiedDate'];

        // Add IsDeleted if object supports soft deletes (most objects do, except User and some system objects)
        if (! in_array($objectName, $this->noSoftDeletes)) {
            $resolvedColumns[] = 'IsDeleted';
        }

        // Get all field names from describe metadata (including read-only fields)
        // When querying, we want access to ALL fields, not just updatable ones
        // Read-only fields include formulas, system fields, and fields with restricted FLS
        if (isset($metadata['fields'])) {
            foreach ($metadata['fields'] as $field) {
                $fieldName = $field['name'] ?? null;
                if ($fieldName && ! in_array($fieldName, $resolvedColumns)) {
                    $resolvedColumns[] = $fieldName;
                }
            }
        }

        // Remove duplicates and return
        return array_unique($resolvedColumns);
    }

    /**
     * Get updateable field names for a Salesforce object
     * Used to filter out read-only fields before create/update operations
     * Note: Salesforce API uses 'updateable' (their spelling) not 'updatable'
     *
     * @param  string|object  $object  Salesforce object name string, SalesforceModel class string, or SalesforceModel instance
     * @return array Array of updateable field names
     *
     * @throws SalesforceException
     * @throws AuthenticationException
     */
    public function getUpdateableFields(string | object $object): array
    {
        // Use describe to get field metadata (cached)
        $metadata = $this->describe($object);

        $updateableFields = [];

        // Extract updateable fields from metadata
        if (isset($metadata['fields'])) {
            foreach ($metadata['fields'] as $field) {
                $isUpdateable = $field['updateable'] ?? false;
                $fieldName = $field['name'] ?? null;

                if ($isUpdateable && $fieldName) {
                    $updateableFields[] = $fieldName;
                }
            }
        }

        return $updateableFields;
    }

    /**
     * Access the underlying Forrest instance for operations not covered by this adapter
     *
     * @throws AuthenticationException
     */
    public function forrest(): mixed
    {
        $this->ensureAuthenticated();

        return app('forrest');
    }

    /**
     * Resolve object name from various input types
     *
     * @param  string|object|null  $object  Salesforce object name string, SalesforceModel class string, SalesforceModel instance, or null
     * @return string|null Salesforce object name or null
     *
     * @throws SalesforceException
     */
    protected function resolveObjectName(string | object | null $object): ?string
    {
        if ($object === null) {
            return null;
        }

        if (is_object($object)) {
            // Handle SalesforceModel instance
            if (! $object instanceof SalesforceModel) {
                throw new SalesforceException('Object must be an instance of SalesforceModel');
            }

            return $object->getTable();
        }

        // String input - could be object name or class string
        if (class_exists($object)) {
            // It's a class string (e.g., Account::class)
            if (! is_subclass_of($object, SalesforceModel::class)) {
                throw new SalesforceException('Class must extend SalesforceModel');
            }

            // Get table name without instantiation using reflection
            return $this->getTableNameFromClass($object);
        }

        // It's a plain Salesforce object name string
        return $object;
    }

    /**
     * Get table name from a model class without instantiation
     *
     * Replicates the logic of Model::getTable() without creating an instance
     *
     * @param  string  $class  Fully qualified class name
     * @return string Table name
     *
     * @throws SalesforceException
     */
    protected function getTableNameFromClass(string $class): string
    {
        try {
            $reflection = new ReflectionClass($class);

            // Check if class has a $table property defined
            if ($reflection->hasProperty('table')) {
                $property = $reflection->getProperty('table');
                $defaultProperties = $reflection->getDefaultProperties();

                // If table property has a default value, use it
                if (isset($defaultProperties['table'])) {
                    return $defaultProperties['table'];
                }
            }

            // Fallback: use class basename (same logic as Model::getTable())
            // Laravel's default: Str::snake(class_basename($class))
            return class_basename($class);
        } catch (ReflectionException $e) {
            throw new SalesforceException("Unable to resolve table name for class {$class}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Ensure authentication before making API calls
     *
     * @throws AuthenticationException
     */
    protected function ensureAuthenticated(): void
    {
        $this->authManager->ensureAuthenticated();
    }
}
