<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Support;

class SalesforceModelGenerator
{
    /**
     * Fields already cast by SalesforceModel::casts() — exclude from generated casts.
     */
    private const array PARENT_CAST_FIELDS = [
        'CreatedDate',
        'LastModifiedDate',
        'SystemModstamp',
        'LastViewedDate',
        'LastReferencedDate',
    ];

    /**
     * Build the casts array from field metadata and the configured cast map.
     * Excludes fields already cast by the parent SalesforceModel.
     *
     * @param  array  $fields   Field metadata from describe response
     * @param  array  $castMap  Salesforce type => Laravel cast mapping
     * @return array  Field name => cast type
     */
    public function buildCasts(array $fields, array $castMap): array
    {
        $casts = [];

        foreach ($fields as $field) {
            $name = $field['name'] ?? null;
            $type = $field['type'] ?? null;

            if (! $name || ! $type) {
                continue;
            }

            // Skip fields already cast by parent model
            if (in_array($name, self::PARENT_CAST_FIELDS, true)) {
                continue;
            }

            // Map Salesforce type to Laravel cast
            if (isset($castMap[$type])) {
                $casts[$name] = $castMap[$type];
            }
        }

        return $casts;
    }

    /**
     * Convert a Salesforce API name to a PHP class name.
     * Strips __c suffix and converts underscores to PascalCase.
     */
    public static function resolveClassName(string $objectName): string
    {
        // Strip __c suffix for custom objects
        $name = preg_replace('/__c$/i', '', $objectName);

        // Convert underscores to PascalCase
        return str_replace('_', '', ucwords($name, '_'));
    }

    /**
     * Determine if the $table property is needed on the model.
     * Only needed when class name differs from Salesforce API name.
     */
    public static function needsTableProperty(string $objectName, string $className): bool
    {
        return $objectName !== $className;
    }

    /**
     * Extract belongsTo relationships from field metadata.
     * Only includes fields where type is 'reference' and referenceTo has exactly one entry.
     * Polymorphic references (multiple referenceTo) are skipped.
     *
     * @param  array  $fields  Field metadata from describe response
     * @return array  Array of relationship definitions
     */
    public function extractBelongsToRelationships(array $fields): array
    {
        $relationships = [];

        foreach ($fields as $field) {
            $type = $field['type'] ?? null;
            $referenceTo = $field['referenceTo'] ?? [];
            $relationshipName = $field['relationshipName'] ?? null;
            $fieldName = $field['name'] ?? null;

            if ($type !== 'reference' || ! $fieldName || ! $relationshipName) {
                continue;
            }

            // Skip polymorphic references
            if (count($referenceTo) !== 1) {
                continue;
            }

            $relationships[] = [
                'type'          => 'belongsTo',
                'relatedObject' => $referenceTo[0],
                'foreignKey'    => $fieldName,
                'methodName'    => lcfirst($relationshipName),
            ];
        }

        return $relationships;
    }

    /**
     * Extract hasMany relationships from childRelationships metadata.
     * Skips entries with null relationshipName (internal Salesforce tracking objects).
     *
     * @param  array  $childRelationships  childRelationships from describe response
     * @return array  Array of relationship definitions
     */
    public function extractHasManyRelationships(array $childRelationships): array
    {
        $relationships = [];

        foreach ($childRelationships as $child) {
            $childObject = $child['childSObject'] ?? null;
            $field = $child['field'] ?? null;
            $relationshipName = $child['relationshipName'] ?? null;

            if (! $childObject || ! $field || ! $relationshipName) {
                continue;
            }

            $relationships[] = [
                'type'          => 'hasMany',
                'relatedObject' => $childObject,
                'foreignKey'    => $field,
                'methodName'    => lcfirst($relationshipName),
            ];
        }

        return $relationships;
    }
}
