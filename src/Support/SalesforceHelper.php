<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Support;

class SalesforceHelper
{
    /**
     * Check if a string is a valid Salesforce ID
     *
     * Salesforce IDs can be either 15 or 18 characters long.
     * 15-character IDs are case-sensitive.
     * 18-character IDs include a checksum and are case-insensitive.
     *
     * @param  mixed  $id
     */
    public static function isValidId(?string $id): bool
    {
        if (is_null($id)) {
            return false;
        }

        $id = trim($id);
        $len = strlen($id);

        // Check length (must be 15 or 18 characters)
        if ($len !== 15 && $len !== 18) {
            return false;
        }

        // Check if alphanumeric
        if (! ctype_alnum($id)) {
            return false;
        }

        // 15-character IDs are valid but skip checksum verification
        if ($len === 15) {
            return true;
        }

        $base_id = substr($id, 0, 15); // Use the first 15 characters to compute the checksum
        $checksum = substr($id, 15);

        // Compute the checksum based on the Salesforce algorithm
        $computed_checksum = '';

        for ($i = 0; $i < 3; $i++) {
            $chunk = substr($base_id, $i * 5, 5);

            $chunk = strrev($chunk);

            $binary_chunk = '';
            foreach (str_split($chunk) as $char) {
                $binary_chunk .= ctype_upper($char) ? '1' : '0';
            }
            // Convert the binary chunk to a numeric index
            $checksum_index = bindec($binary_chunk);

            // Append the character corresponding to the index
            $computed_checksum .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ012345'[$checksum_index];
        }

        // Check if the computed checksum matches the provided checksum (case-insensitive)
        return strtoupper($computed_checksum) === strtoupper($checksum);
    }

    /**
     * Recursively extract updatable field names from Salesforce field metadata
     *
     * @param  array  $fields  Array of field metadata from Salesforce describe
     * @param  array  $columns  Reference to array that will contain the field names
     * @return array Array of updatable field names
     */
    public static function getUpdatableFieldNames(array $fields, array &$columns = []): array
    {
        foreach ($fields as $field) {
            // Check if field is updatable
            // Note: Salesforce API uses 'updateable' (their spelling) not 'updatable'
            $isUpdatable = $field['details']['updateable'] ?? $field['updateable'] ?? false;
            $fieldName = $field['details']['name'] ?? $field['name'] ?? null;

            if ($isUpdatable && $fieldName) {
                $columns[] = $fieldName;
            }

            // Recursively process compound fields (e.g., Address fields)
            if (isset($field['components']) && is_array($field['components'])) {
                static::getUpdatableFieldNames($field['components'], $columns);
            }
        }

        return $columns;
    }
}
