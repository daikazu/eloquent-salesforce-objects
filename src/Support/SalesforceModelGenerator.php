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
     * @param  array  $fields  Field metadata from describe response
     * @param  array  $castMap  Salesforce type => Laravel cast mapping
     * @return array Field name => cast type
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
     * Convert a Salesforce relationship name to a PHP method name.
     * Strips __r suffix, converts underscores to camelCase.
     */
    public static function resolveMethodName(string $relationshipName): string
    {
        // Strip __r suffix for custom relationships
        $name = preg_replace('/__r$/i', '', $relationshipName);

        // Convert to camelCase (first letter lowercase, underscores removed)
        return lcfirst(str_replace('_', '', ucwords($name, '_')));
    }

    /**
     * Get foreign key column names from selected relationships.
     * Used to ensure belongsTo foreign keys are included in $defaultColumns.
     *
     * @param  array  $relationships  Selected relationship definitions
     * @return array Foreign key column names from belongsTo relationships
     */
    public static function getRelationshipForeignKeys(array $relationships): array
    {
        $keys = [];

        foreach ($relationships as $rel) {
            if (($rel['type'] ?? '') === 'belongsTo') {
                $keys[] = $rel['foreignKey'];
            }
        }

        return $keys;
    }

    /**
     * Extract belongsTo relationships from field metadata.
     * Only includes fields where type is 'reference' and referenceTo has exactly one entry.
     * Polymorphic references (multiple referenceTo) are skipped.
     *
     * @param  array  $fields  Field metadata from describe response
     * @return array Array of relationship definitions
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
                'methodName'    => self::resolveMethodName($relationshipName),
            ];
        }

        return $relationships;
    }

    /**
     * Extract hasMany relationships from childRelationships metadata.
     * Skips entries with null relationshipName (internal Salesforce tracking objects).
     *
     * @param  array  $childRelationships  childRelationships from describe response
     * @return array Array of relationship definitions
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
                'methodName'    => self::resolveMethodName($relationshipName),
            ];
        }

        return $relationships;
    }

    /**
     * Generate a Salesforce model class from structured input.
     *
     * @param  array{
     *     className: string,
     *     objectName: string,
     *     namespace: string,
     *     fields: ?array,
     *     casts: array,
     *     relationships: array,
     * }  $config
     * @return string Generated PHP file content
     */
    public function generate(array $config): string
    {
        $stub = file_get_contents($this->getStubPath());

        $replacements = [
            '{{ namespace }}'      => $config['namespace'],
            '{{ className }}'      => $config['className'],
            '{{ imports }}'        => $this->renderImports($config['relationships']),
            '{{ table }}'          => $this->renderTable($config['objectName'], $config['className']),
            '{{ defaultColumns }}' => $this->renderDefaultColumns($config['fields']),
            '{{ casts }}'          => $this->renderCasts($config['casts']),
            '{{ relationships }}'  => $this->renderRelationships($config['relationships']),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $stub);
    }

    /**
     * Get the path to the stub template.
     */
    protected function getStubPath(): string
    {
        $customStub = base_path('stubs/salesforce-model.stub');

        if (file_exists($customStub)) {
            return $customStub;
        }

        return dirname(__DIR__, 2) . '/stubs/salesforce-model.stub';
    }

    protected function renderImports(array $relationships): string
    {
        $imports = [];

        foreach ($relationships as $rel) {
            $class = $rel['relatedClass'] ?? null;
            if ($class) {
                $imports[] = "use {$class};";
            }
        }

        if ($imports === []) {
            return '';
        }

        return "\n" . implode("\n", array_unique($imports));
    }

    protected function renderTable(string $objectName, string $className): string
    {
        if (! self::needsTableProperty($objectName, $className)) {
            return '';
        }

        return "    protected \$table = '{$objectName}';\n\n";
    }

    protected function renderDefaultColumns(?array $fields): string
    {
        if ($fields === null) {
            return '';
        }

        $items = implode("\n", array_map(fn (string $f): string => "        '{$f}',", $fields));

        return "    protected ?array \$defaultColumns = [\n{$items}\n    ];\n\n";
    }

    protected function renderCasts(array $casts): string
    {
        if ($casts === []) {
            return '';
        }

        $items = implode("\n", array_map(
            fn (string $cast, string $field): string => "            '{$field}' => '{$cast}',",
            $casts,
            array_keys($casts)
        ));

        return <<<PHP
            protected function casts(): array
            {
                return array_merge(parent::casts(), [
        {$items}
                ]);
            }

        PHP;
    }

    protected function renderRelationships(array $relationships): string
    {
        if ($relationships === []) {
            return '';
        }

        $methods = [];

        foreach ($relationships as $rel) {
            $method = $rel['methodName'];
            $type = $rel['type'];
            $class = class_basename($rel['relatedClass']);
            $foreignKey = $rel['foreignKey'];
            $modelExists = $rel['modelExists'] ?? true;

            if ($modelExists) {
                $methods[] = <<<PHP
                    public function {$method}()
                    {
                        return \$this->{$type}({$class}::class, '{$foreignKey}');
                    }
                PHP;
            } else {
                $methods[] = <<<PHP
                    // TODO: Generate {$class} model — php artisan make:salesforce-model {$rel['relatedObject']}
                    public function {$method}()
                    {
                        return \$this->{$type}({$class}::class, '{$foreignKey}');
                    }
                PHP;
            }
        }

        return implode("\n\n", $methods) . "\n";
    }
}
