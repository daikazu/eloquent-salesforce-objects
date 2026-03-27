<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Commands;

use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;
use Daikazu\EloquentSalesforceObjects\Support\SalesforceModelGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class MakeSalesforceModelCommand extends Command
{
    protected $signature = 'make:salesforce-model
                            {object? : Salesforce object API name (e.g. Account, My_Object__c)}
                            {--path= : Override output directory}
                            {--all-fields : Skip field selection, use all fields}
                            {--no-relationships : Skip relationship detection}
                            {--force : Overwrite existing model without prompting}';

    protected $description = 'Generate a Salesforce model from live object metadata';

    public function __construct(
        private readonly SalesforceAdapter $adapter,
        private readonly SalesforceModelGenerator $generator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $objectName = $this->resolveObjectName();

        if (! $objectName) {
            $this->error('No object selected.');
            return self::FAILURE;
        }

        $this->info("Fetching metadata for {$objectName}...");

        try {
            $metadata = $this->adapter->describe($objectName);
        } catch (Throwable $e) {
            $this->error("Failed to describe {$objectName}: {$e->getMessage()}");
            return self::FAILURE;
        }

        $fields = $metadata['fields'] ?? [];
        $childRelationships = $metadata['childRelationships'] ?? [];

        $suggestedName = SalesforceModelGenerator::resolveClassName($objectName);
        $className = text(
            label: 'Class name for the model',
            default: $suggestedName,
            required: true,
        );

        $outputPath = $this->option('path') ?? config('eloquent-salesforce-objects.model_generation.path');
        $namespace = config('eloquent-salesforce-objects.model_generation.namespace');

        if ($this->option('path')) {
            $namespace = $this->pathToNamespace($outputPath);
        }

        $selectedFields = $this->selectFields($fields);

        $castMap = config('eloquent-salesforce-objects.model_generation.cast_map', []);
        $fieldsForCasts = $selectedFields === null ? $fields : array_filter(
            $fields,
            fn (array $f): bool => in_array($f['name'] ?? '', $selectedFields, true),
        );
        $casts = $this->generator->buildCasts($fieldsForCasts, $castMap);

        $relationships = $this->selectRelationships($fields, $childRelationships, $namespace, $outputPath);

        // Ensure belongsTo foreign key columns are in $defaultColumns
        if ($selectedFields !== null && $relationships !== []) {
            $foreignKeys = SalesforceModelGenerator::getRelationshipForeignKeys($relationships);
            foreach ($foreignKeys as $fk) {
                if (! in_array($fk, $selectedFields, true)) {
                    $selectedFields[] = $fk;
                }
            }
        }

        $content = $this->generator->generate([
            'className'     => $className,
            'objectName'    => $objectName,
            'namespace'     => $namespace,
            'fields'        => $selectedFields,
            'casts'         => $casts,
            'relationships' => $relationships,
        ]);

        $filePath = rtrim($outputPath, '/') . "/{$className}.php";

        $written = $this->writeFile($filePath, $content);

        if ($written === null) {
            // User chose to skip — not an error
            return self::SUCCESS;
        }

        $this->info("Model created: {$filePath}");

        return self::SUCCESS;
    }

    private function resolveObjectName(): ?string
    {
        $objectName = $this->argument('object');

        if ($objectName) {
            return $objectName;
        }

        $this->info('Fetching available Salesforce objects...');

        try {
            $global = $this->adapter->describeGlobal();
        } catch (Throwable $e) {
            $this->error("Failed to fetch object list: {$e->getMessage()}");
            return null;
        }

        $objects = collect($global['sobjects'] ?? [])
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        if ($objects === []) {
            $this->error('No Salesforce objects found.');
            return null;
        }

        return search(
            label: 'Search for a Salesforce object',
            options: fn (string $value) => array_values(array_filter(
                $objects,
                fn (string $name): bool => str_contains(strtolower($name), strtolower($value)),
            )),
            placeholder: 'Type to search (e.g. Account, Opportunity)',
        );
    }

    private function selectFields(array $fields): ?array
    {
        if ($this->option('all-fields')) {
            return null;
        }

        $choice = select(
            label: 'Which fields should be included in $defaultColumns?',
            options: [
                'all'    => 'All fields (use * in queries)',
                'select' => 'Select specific fields',
            ],
        );

        if ($choice === 'all') {
            return null;
        }

        $requiredFields = [];
        $optionalFields = [];

        foreach ($fields as $field) {
            $name = $field['name'] ?? null;
            $nillable = $field['nillable'] ?? true;
            $createable = $field['createable'] ?? false;

            if (! $name || $name === 'Id') {
                continue;
            }

            if ($createable && ! $nillable) {
                $requiredFields[] = $name;
            } else {
                $optionalFields[] = $name;
            }
        }

        if ($optionalFields === []) {
            $this->info('No optional fields available. Using required fields only.');
            return $requiredFields;
        }

        $selected = multiselect(
            label: 'Select fields (required fields are always included)',
            options: $optionalFields,
            hint: 'Required fields auto-included: ' . implode(', ', $requiredFields),
        );

        return array_merge($requiredFields, $selected);
    }

    private function selectRelationships(
        array $fields,
        array $childRelationships,
        string $namespace,
        string $outputPath,
    ): array {
        if ($this->option('no-relationships')) {
            return [];
        }

        $belongsTo = $this->generator->extractBelongsToRelationships($fields);
        $hasMany = $this->generator->extractHasManyRelationships($childRelationships);

        $allRelationships = array_merge($belongsTo, $hasMany);

        if ($allRelationships === []) {
            return [];
        }

        // Resolve class names and build selectable options keyed by index
        $indexedRelationships = [];
        $options = [];
        foreach ($allRelationships as $rel) {
            $relatedClassName = SalesforceModelGenerator::resolveClassName($rel['relatedObject']);
            $relatedFilePath = rtrim($outputPath, '/') . "/{$relatedClassName}.php";
            $rel['relatedClass'] = "{$namespace}\\{$relatedClassName}";
            $rel['modelExists'] = file_exists($relatedFilePath);

            $label = "{$rel['type']} {$rel['relatedObject']} (via {$rel['foreignKey']})";
            if (! $rel['modelExists']) {
                $label .= ' — model not yet generated';
            }

            // Use label as both key and value so multiselect return is predictable
            $options[$label] = $label;
            $indexedRelationships[$label] = $rel;
        }

        $selected = multiselect(
            label: 'Select relationships to include',
            options: $options,
            hint: 'Models that don\'t exist yet will work once generated',
        );

        return array_map(fn ($label) => $indexedRelationships[$label], $selected);
    }

    private function writeFile(string $filePath, string $content): ?bool
    {
        $directory = dirname($filePath);
        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        if (File::exists($filePath) && ! $this->option('force')) {
            $action = select(
                label: "Model file already exists: {$filePath}",
                options: [
                    'overwrite' => 'Overwrite — replace the existing file',
                    'diff'      => 'Show diff — display what would change',
                    'skip'      => 'Skip — cancel generation for this model',
                ],
            );

            if ($action === 'skip') {
                $this->info('Skipped.');
                return null;
            }

            if ($action === 'diff') {
                $existing = File::get($filePath);
                $this->line('');
                $this->line('<fg=red>--- Existing</>');
                $this->line('<fg=green>+++ Generated</>');
                $this->line('');

                $existingLines = explode("\n", $existing);
                $generatedLines = explode("\n", $content);
                $maxLines = max(count($existingLines), count($generatedLines));

                for ($i = 0; $i < $maxLines; $i++) {
                    $existingLine = $existingLines[$i] ?? '';
                    $generatedLine = $generatedLines[$i] ?? '';

                    if ($existingLine !== $generatedLine) {
                        if ($existingLine !== '') {
                            $this->line("<fg=red>- {$existingLine}</>");
                        }
                        if ($generatedLine !== '') {
                            $this->line("<fg=green>+ {$generatedLine}</>");
                        }
                    } else {
                        $this->line("  {$existingLine}");
                    }
                }

                $this->line('');

                if (! confirm('Overwrite with generated version?')) {
                    $this->info('Skipped.');
                    return null;
                }
            }
        }

        File::put($filePath, $content);

        return true;
    }

    private function pathToNamespace(string $path): string
    {
        $appPath = app_path();
        $relativePath = str_replace($appPath, '', $path);
        $namespace = 'App' . str_replace('/', '\\', $relativePath);

        return rtrim($namespace, '\\');
    }
}
