<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Support;

use Illuminate\Support\Str;

class ResponseParser
{
    protected bool $enableFieldMapping;

    protected string $fieldNamingConvention;

    protected array $customFieldMappings;

    public function __construct()
    {
        $this->enableFieldMapping = config('eloquent-salesforce-objects.enable_field_mapping', false);
        $this->fieldNamingConvention = config('eloquent-salesforce-objects.field_naming_convention', 'snake_case');
        $this->customFieldMappings = config('eloquent-salesforce-objects.field_mappings', []);
    }

    /**
     * Parse a query response with pagination info
     */
    public function parseQueryResponse(mixed $response): array
    {
        $data = $this->normalize($response);

        return [
            'records'        => $this->mapRecords($data['records'] ?? []),
            'totalSize'      => $data['totalSize'] ?? 0,
            'done'           => $data['done'] ?? true,
            'nextRecordsUrl' => $data['nextRecordsUrl'] ?? null,
        ];
    }

    /**
     * Parse a single record response
     */
    public function parseRecordResponse(mixed $response): array
    {
        $data = $this->normalize($response);

        return $this->mapFields($data);
    }

    /**
     * Parse a metadata/describe response
     */
    public function parseMetadataResponse(mixed $response): array
    {
        return $this->normalize($response);
    }

    /**
     * Parse a list response (versions, resources, etc.)
     */
    public function parseListResponse(mixed $response): array
    {
        return $this->normalize($response);
    }

    /**
     * Parse a create/upsert response
     */
    public function parseCreateResponse(mixed $response): array
    {
        $data = $this->normalize($response);

        // Salesforce returns: {id: "...", success: true, errors: []}
        return [
            'id'      => $data['id'] ?? null,
            'success' => $data['success'] ?? false,
            'errors'  => $data['errors'] ?? [],
        ];
    }

    /**
     * Normalize response to array format
     */
    protected function normalize(mixed $response): array
    {
        if (is_array($response)) {
            return $response;
        }

        if (is_string($response)) {
            $decoded = json_decode($response, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Map multiple records
     */
    protected function mapRecords(array $records): array
    {
        return array_map(
            fn ($record): array => $this->mapFields($record),
            $records
        );
    }

    /**
     * Map field names in a single record
     */
    protected function mapFields(array $data): array
    {
        // If field mapping is disabled, return data as-is (but still skip attributes)
        if (! $this->enableFieldMapping) {
            return $this->stripAttributes($data);
        }

        $mapped = [];

        foreach ($data as $key => $value) {
            // Skip Salesforce metadata fields
            if ($key === 'attributes') {
                continue;
            }

            // Handle nested relationship queries
            if (is_array($value) && isset($value['records'])) {
                $mapped[$this->mapFieldName($key)] = $this->mapRecords($value['records']);

                continue;
            }

            // Handle nested objects
            if (is_array($value) && $value !== []) {
                $mapped[$this->mapFieldName($key)] = $this->mapFields($value);

                continue;
            }

            // Map the field name and value
            $mapped[$this->mapFieldName($key)] = $value;
        }

        return $mapped;
    }

    /**
     * Strip Salesforce metadata fields without mapping
     */
    protected function stripAttributes(array $data): array
    {
        $cleaned = [];

        foreach ($data as $key => $value) {
            // Skip Salesforce metadata fields
            if ($key === 'attributes') {
                continue;
            }

            // Handle nested relationship queries
            if (is_array($value) && isset($value['records'])) {
                $cleaned[$key] = array_map(
                    fn ($record): array => $this->stripAttributes($record),
                    $value['records']
                );

                continue;
            }

            // Handle nested objects
            if (is_array($value) && $value !== []) {
                $cleaned[$key] = $this->stripAttributes($value);

                continue;
            }

            $cleaned[$key] = $value;
        }

        return $cleaned;
    }

    /**
     * Map a single field name based on convention
     */
    protected function mapFieldName(string $fieldName): string
    {
        // Check custom mappings first (inverse lookup)
        $customMapping = array_search($fieldName, $this->customFieldMappings, true);
        if ($customMapping !== false) {
            return $customMapping;
        }

        // Store original for reference
        $original = $fieldName;

        // Extract and remove namespace prefix (e.g., MyNamespace__Field__c)
        $namespace = null;
        if (preg_match('/^(\w+)__(\w+)__([cr])$/', $fieldName, $matches)) {
            $namespace = $matches[1];
            $fieldName = $matches[2];
            $suffix = '__' . $matches[3]; // __c or __r
        }
        // Extract and remove custom field suffix (e.g., Custom_Field__c)
        elseif (str_ends_with($fieldName, '__c') || str_ends_with($fieldName, '__r')) {
            $suffix = substr($fieldName, -3); // __c or __r
            $fieldName = substr($fieldName, 0, -3);
        } else {
            $suffix = null;
        }

        // Apply naming convention to the clean field name
        $mapped = match ($this->fieldNamingConvention) {
            'snake_case' => Str::snake($fieldName),
            'camelCase'  => Str::camel($fieldName),
            'PascalCase' => Str::studly($fieldName),
            default      => $fieldName,
        };

        // For standard Salesforce fields, return as-is (Id, Name, etc.)
        // Check if the original was already in the correct format
        if ($suffix === null && $namespace === null && ! str_contains($original, '_')) {
            return match ($this->fieldNamingConvention) {
                'snake_case' => Str::snake($original),
                'camelCase'  => Str::camel($original),
                'PascalCase' => Str::studly($original),
                default      => $original,
            };
        }

        return $mapped;
    }

    /**
     * Reverse map field name (Laravel -> Salesforce)
     */
    public function reverseMapFieldName(string $fieldName): string
    {
        // Check custom mappings
        if (isset($this->customFieldMappings[$fieldName])) {
            return $this->customFieldMappings[$fieldName];
        }

        // Check if this looks like it needs __c suffix restoration
        // This is a heuristic - if it's snake_case and all lowercase, likely a custom field
        $needsCustomSuffix = $this->fieldNamingConvention === 'snake_case'
            && preg_match('/^[a-z][a-z0-9_]*$/', $fieldName)
            && ! in_array($fieldName, ['id', 'name', 'type']); // Common standard fields

        // For snake_case to PascalCase (most common Salesforce pattern)
        if ($this->fieldNamingConvention === 'snake_case') {
            $mapped = Str::studly($fieldName);

            // Add __c suffix if it looks like a custom field
            // Note: This is imperfect - custom mappings are more reliable
            return $needsCustomSuffix ? $mapped . '__c' : $mapped;
        }

        // For other conventions, return as-is
        return $fieldName;
    }

    /**
     * Reverse map entire data array (Laravel -> Salesforce)
     */
    public function reverseMapFields(array $data): array
    {
        // If field mapping is disabled, return data as-is
        if (! $this->enableFieldMapping) {
            return $data;
        }

        $mapped = [];

        foreach ($data as $key => $value) {
            $mapped[$this->reverseMapFieldName($key)] = is_array($value) ? $this->reverseMapFields($value) : $value;
        }

        return $mapped;
    }
}
