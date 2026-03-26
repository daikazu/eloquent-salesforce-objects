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
}
