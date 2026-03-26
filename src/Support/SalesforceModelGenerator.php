<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Support;

class SalesforceModelGenerator
{
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
