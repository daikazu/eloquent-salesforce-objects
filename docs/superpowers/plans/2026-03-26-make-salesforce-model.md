# `make:salesforce-model` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an artisan command that generates Salesforce model classes from live describe metadata, with interactive field/relationship selection.

**Architecture:** Command + Generator Service. `MakeSalesforceModelCommand` handles all interactive CLI prompts. `SalesforceModelGenerator` is a pure service that takes structured input and returns generated PHP content. A `.stub` template drives the output format.

**Tech Stack:** Laravel Artisan Commands, Laravel Prompts, Pest v2 with Mockery, Spatie Laravel Package Tools

**Spec:** `docs/superpowers/specs/2026-03-26-make-salesforce-model-design.md`

---

## File Structure

| Action | File | Responsibility |
|--------|------|---------------|
| Create | `src/Support/SalesforceModelGenerator.php` | Pure generation logic: name resolution, stub rendering, cast mapping |
| Create | `src/Commands/MakeSalesforceModelCommand.php` | Interactive CLI flow: prompts, validation, file writing |
| Create | `stubs/salesforce-model.stub` | Publishable model template |
| Modify | `src/Models/SalesforceModel.php:194-200` | Add system timestamp casts |
| Modify | `config/eloquent-salesforce-objects.php` | Add `model_generation` config section |
| Modify | `src/EloquentSalesforceObjectsServiceProvider.php:24-29` | Register new command |
| Create | `tests/Unit/SalesforceModelGeneratorTest.php` | Generator unit tests |
| Create | `tests/Unit/MakeSalesforceModelCommandTest.php` | Command integration tests |

---

### Task 1: Add System Timestamp Casts to Parent Model

**Files:**
- Modify: `src/Models/SalesforceModel.php:194-200`
- Test: `tests/Unit/TimestampCastingTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/TimestampCastingTest.php` inside the existing `describe('timestamp casting', ...)` block:

```php
it('casts SystemModstamp to Carbon instance', function () {
    Forrest::shouldReceive('hasToken')->andReturn(true);

    $account = new Account([
        'Id'             => '001xx000003DGb2AAG',
        'Name'           => 'Test',
        'SystemModstamp' => '2024-01-15T10:30:00.000+0000',
    ]);

    expect($account->SystemModstamp)->toBeInstanceOf(Carbon\Carbon::class);
});

it('casts LastViewedDate to Carbon instance', function () {
    Forrest::shouldReceive('hasToken')->andReturn(true);

    $account = new Account([
        'Id'             => '001xx000003DGb2AAG',
        'Name'           => 'Test',
        'LastViewedDate' => '2024-01-15T10:30:00.000+0000',
    ]);

    expect($account->LastViewedDate)->toBeInstanceOf(Carbon\Carbon::class);
});

it('casts LastReferencedDate to Carbon instance', function () {
    Forrest::shouldReceive('hasToken')->andReturn(true);

    $account = new Account([
        'Id'               => '001xx000003DGb2AAG',
        'Name'             => 'Test',
        'LastReferencedDate' => '2024-01-15T10:30:00.000+0000',
    ]);

    expect($account->LastReferencedDate)->toBeInstanceOf(Carbon\Carbon::class);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/TimestampCastingTest.php --filter="SystemModstamp|LastViewedDate|LastReferencedDate"`

Expected: FAIL — these fields are not cast yet.

- [ ] **Step 3: Update SalesforceModel casts**

In `src/Models/SalesforceModel.php`, replace the `casts()` method:

```php
protected function casts(): array
{
    return [
        'CreatedDate'        => 'datetime',
        'LastModifiedDate'   => 'datetime',
        'SystemModstamp'     => 'datetime',
        'LastViewedDate'     => 'datetime',
        'LastReferencedDate' => 'datetime',
    ];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/TimestampCastingTest.php`

Expected: All timestamp tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Models/SalesforceModel.php tests/Unit/TimestampCastingTest.php
git commit -m "feat: add SystemModstamp, LastViewedDate, LastReferencedDate to parent casts"
```

---

### Task 2: Add Config Section

**Files:**
- Modify: `config/eloquent-salesforce-objects.php`

- [ ] **Step 1: Add model_generation config**

Append before the closing `];` in `config/eloquent-salesforce-objects.php`:

```php
/*
|--------------------------------------------------------------------------
| Model Generation
|--------------------------------------------------------------------------
|
| Configuration for the make:salesforce-model artisan command.
|
| 'path' - Default directory for generated Salesforce models
| 'namespace' - Default namespace for generated models
| 'cast_map' - Maps Salesforce field types to Laravel cast types
|
*/
'model_generation' => [
    'path'      => app_path('Models/Salesforce'),
    'namespace' => 'App\\Models\\Salesforce',
    'cast_map'  => [
        'datetime' => 'datetime',
        'date'     => 'date',
        'boolean'  => 'boolean',
        'double'   => 'float',
        'currency' => 'float',
        'percent'  => 'float',
        'int'      => 'integer',
    ],
],
```

- [ ] **Step 2: Commit**

```bash
git add config/eloquent-salesforce-objects.php
git commit -m "feat: add model_generation config section"
```

---

### Task 3: Create the Stub Template

**Files:**
- Create: `stubs/salesforce-model.stub`

- [ ] **Step 1: Create the stub file**

Create `stubs/salesforce-model.stub`:

```php
<?php

declare(strict_types=1);

namespace {{ namespace }};

use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;
{{ imports }}

class {{ className }} extends SalesforceModel
{
{{ table }}{{ defaultColumns }}{{ casts }}{{ relationships }}}
```

- [ ] **Step 2: Commit**

```bash
git add stubs/salesforce-model.stub
git commit -m "feat: add salesforce model stub template"
```

---

### Task 4: Build the Generator Service — Name Resolution

**Files:**
- Create: `src/Support/SalesforceModelGenerator.php`
- Create: `tests/Unit/SalesforceModelGeneratorTest.php`

- [ ] **Step 1: Write failing tests for name resolution**

Create `tests/Unit/SalesforceModelGeneratorTest.php`:

```php
<?php

use Daikazu\EloquentSalesforceObjects\Support\SalesforceModelGenerator;

describe('name resolution', function () {
    it('passes through standard object names', function () {
        expect(SalesforceModelGenerator::resolveClassName('Account'))->toBe('Account');
        expect(SalesforceModelGenerator::resolveClassName('Contact'))->toBe('Contact');
        expect(SalesforceModelGenerator::resolveClassName('Opportunity'))->toBe('Opportunity');
    });

    it('strips __c suffix and converts to PascalCase', function () {
        expect(SalesforceModelGenerator::resolveClassName('My_Object__c'))->toBe('MyObject');
        expect(SalesforceModelGenerator::resolveClassName('Opportunity_Product__c'))->toBe('OpportunityProduct');
        expect(SalesforceModelGenerator::resolveClassName('Custom__c'))->toBe('Custom');
    });

    it('handles single word custom objects', function () {
        expect(SalesforceModelGenerator::resolveClassName('Invoice__c'))->toBe('Invoice');
    });

    it('determines when table property is needed', function () {
        expect(SalesforceModelGenerator::needsTableProperty('Account', 'Account'))->toBeFalse();
        expect(SalesforceModelGenerator::needsTableProperty('My_Object__c', 'MyObject'))->toBeTrue();
        expect(SalesforceModelGenerator::needsTableProperty('Opportunity', 'CustomOpportunity'))->toBeTrue();
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/SalesforceModelGeneratorTest.php`

Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement name resolution**

Create `src/Support/SalesforceModelGenerator.php`:

```php
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/SalesforceModelGeneratorTest.php`

Expected: All PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Support/SalesforceModelGenerator.php tests/Unit/SalesforceModelGeneratorTest.php
git commit -m "feat: add SalesforceModelGenerator with name resolution"
```

---

### Task 5: Build the Generator Service — Cast Mapping

**Files:**
- Modify: `src/Support/SalesforceModelGenerator.php`
- Modify: `tests/Unit/SalesforceModelGeneratorTest.php`

- [ ] **Step 1: Write failing tests for cast mapping**

Add to `tests/Unit/SalesforceModelGeneratorTest.php`:

```php
describe('cast mapping', function () {
    it('maps Salesforce field types to Laravel casts', function () {
        $fields = [
            ['name' => 'CloseDate', 'type' => 'date', 'createable' => true, 'updateable' => true],
            ['name' => 'IsActive', 'type' => 'boolean', 'createable' => true, 'updateable' => true],
            ['name' => 'Amount', 'type' => 'currency', 'createable' => true, 'updateable' => true],
            ['name' => 'Name', 'type' => 'string', 'createable' => true, 'updateable' => true],
            ['name' => 'Count', 'type' => 'int', 'createable' => true, 'updateable' => true],
        ];

        $castMap = config('eloquent-salesforce-objects.model_generation.cast_map');
        $generator = new SalesforceModelGenerator();
        $casts = $generator->buildCasts($fields, $castMap);

        expect($casts)->toBe([
            'CloseDate' => 'date',
            'IsActive'  => 'boolean',
            'Amount'    => 'float',
            'Count'     => 'integer',
        ]);
        // 'Name' (type: string) should not appear — no mapping for string
    });

    it('excludes parent model system timestamp fields', function () {
        $fields = [
            ['name' => 'CreatedDate', 'type' => 'datetime', 'createable' => false, 'updateable' => false],
            ['name' => 'LastModifiedDate', 'type' => 'datetime', 'createable' => false, 'updateable' => false],
            ['name' => 'SystemModstamp', 'type' => 'datetime', 'createable' => false, 'updateable' => false],
            ['name' => 'LastViewedDate', 'type' => 'datetime', 'createable' => false, 'updateable' => false],
            ['name' => 'LastReferencedDate', 'type' => 'datetime', 'createable' => false, 'updateable' => false],
            ['name' => 'CustomDate__c', 'type' => 'datetime', 'createable' => true, 'updateable' => true],
        ];

        $castMap = config('eloquent-salesforce-objects.model_generation.cast_map');
        $generator = new SalesforceModelGenerator();
        $casts = $generator->buildCasts($fields, $castMap);

        expect($casts)->toBe(['CustomDate__c' => 'datetime']);
    });

    it('returns empty array when no fields need casting', function () {
        $fields = [
            ['name' => 'Name', 'type' => 'string', 'createable' => true, 'updateable' => true],
            ['name' => 'Description', 'type' => 'textarea', 'createable' => true, 'updateable' => true],
        ];

        $castMap = config('eloquent-salesforce-objects.model_generation.cast_map');
        $generator = new SalesforceModelGenerator();
        $casts = $generator->buildCasts($fields, $castMap);

        expect($casts)->toBe([]);
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/SalesforceModelGeneratorTest.php --filter="cast mapping"`

Expected: FAIL — `buildCasts` method does not exist.

- [ ] **Step 3: Implement cast mapping**

Add to `src/Support/SalesforceModelGenerator.php`:

```php
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/SalesforceModelGeneratorTest.php`

Expected: All PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Support/SalesforceModelGenerator.php tests/Unit/SalesforceModelGeneratorTest.php
git commit -m "feat: add cast mapping to SalesforceModelGenerator"
```

---

### Task 6: Build the Generator Service — Relationship Detection

**Files:**
- Modify: `src/Support/SalesforceModelGenerator.php`
- Modify: `tests/Unit/SalesforceModelGeneratorTest.php`

- [ ] **Step 1: Write failing tests for relationship extraction**

Add to `tests/Unit/SalesforceModelGeneratorTest.php`:

```php
describe('relationship extraction', function () {
    it('extracts belongsTo from reference fields', function () {
        $fields = [
            [
                'name' => 'AccountId',
                'type' => 'reference',
                'referenceTo' => ['Account'],
                'relationshipName' => 'Account',
                'createable' => true,
                'updateable' => true,
            ],
            [
                'name' => 'Name',
                'type' => 'string',
                'createable' => true,
                'updateable' => true,
            ],
        ];

        $generator = new SalesforceModelGenerator();
        $relationships = $generator->extractBelongsToRelationships($fields);

        expect($relationships)->toHaveCount(1);
        expect($relationships[0])->toBe([
            'type'         => 'belongsTo',
            'relatedObject' => 'Account',
            'foreignKey'   => 'AccountId',
            'methodName'   => 'account',
        ]);
    });

    it('skips polymorphic references', function () {
        $fields = [
            [
                'name' => 'WhoId',
                'type' => 'reference',
                'referenceTo' => ['Contact', 'Lead'],
                'relationshipName' => 'Who',
                'createable' => true,
                'updateable' => true,
            ],
        ];

        $generator = new SalesforceModelGenerator();
        $relationships = $generator->extractBelongsToRelationships($fields);

        expect($relationships)->toHaveCount(0);
    });

    it('extracts hasMany from childRelationships', function () {
        $childRelationships = [
            [
                'childSObject' => 'Contact',
                'field' => 'AccountId',
                'relationshipName' => 'Contacts',
            ],
            [
                'childSObject' => 'Opportunity',
                'field' => 'AccountId',
                'relationshipName' => 'Opportunities',
            ],
        ];

        $generator = new SalesforceModelGenerator();
        $relationships = $generator->extractHasManyRelationships($childRelationships);

        expect($relationships)->toHaveCount(2);
        expect($relationships[0])->toBe([
            'type'         => 'hasMany',
            'relatedObject' => 'Contact',
            'foreignKey'   => 'AccountId',
            'methodName'   => 'contacts',
        ]);
    });

    it('skips childRelationships with null relationshipName', function () {
        $childRelationships = [
            [
                'childSObject' => 'ContactHistory',
                'field' => 'ContactId',
                'relationshipName' => null,
            ],
        ];

        $generator = new SalesforceModelGenerator();
        $relationships = $generator->extractHasManyRelationships($childRelationships);

        expect($relationships)->toHaveCount(0);
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/SalesforceModelGeneratorTest.php --filter="relationship"`

Expected: FAIL — methods do not exist.

- [ ] **Step 3: Implement relationship extraction**

Add to `src/Support/SalesforceModelGenerator.php`:

```php
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/SalesforceModelGeneratorTest.php`

Expected: All PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Support/SalesforceModelGenerator.php tests/Unit/SalesforceModelGeneratorTest.php
git commit -m "feat: add relationship extraction to SalesforceModelGenerator"
```

---

### Task 7: Build the Generator Service — Stub Rendering

**Files:**
- Modify: `src/Support/SalesforceModelGenerator.php`
- Modify: `tests/Unit/SalesforceModelGeneratorTest.php`

- [ ] **Step 1: Write failing tests for full generation**

Add to `tests/Unit/SalesforceModelGeneratorTest.php`:

```php
describe('generate', function () {
    it('generates a basic model with no custom fields or relationships', function () {
        $generator = new SalesforceModelGenerator();

        $output = $generator->generate([
            'className'     => 'Account',
            'objectName'    => 'Account',
            'namespace'     => 'App\\Models\\Salesforce',
            'fields'        => null, // all fields
            'casts'         => [],
            'relationships' => [],
        ]);

        expect($output)->toContain('namespace App\\Models\\Salesforce;')
            ->toContain('class Account extends SalesforceModel')
            ->not->toContain('protected $table')
            ->not->toContain('protected ?array $defaultColumns')
            ->not->toContain('function casts');
    });

    it('generates model with table property when name differs', function () {
        $generator = new SalesforceModelGenerator();

        $output = $generator->generate([
            'className'     => 'OpportunityProduct',
            'objectName'    => 'Opportunity_Product__c',
            'namespace'     => 'App\\Models\\Salesforce',
            'fields'        => null,
            'casts'         => [],
            'relationships' => [],
        ]);

        expect($output)->toContain("protected \$table = 'Opportunity_Product__c';")
            ->toContain('class OpportunityProduct extends SalesforceModel');
    });

    it('generates model with defaultColumns when fields are specified', function () {
        $generator = new SalesforceModelGenerator();

        $output = $generator->generate([
            'className'     => 'Account',
            'objectName'    => 'Account',
            'namespace'     => 'App\\Models\\Salesforce',
            'fields'        => ['Name', 'Industry', 'Website'],
            'casts'         => [],
            'relationships' => [],
        ]);

        expect($output)->toContain('protected ?array $defaultColumns = [')
            ->toContain("'Name',")
            ->toContain("'Industry',")
            ->toContain("'Website',");
    });

    it('generates model with casts', function () {
        $generator = new SalesforceModelGenerator();

        $output = $generator->generate([
            'className'     => 'Opportunity',
            'objectName'    => 'Opportunity',
            'namespace'     => 'App\\Models\\Salesforce',
            'fields'        => null,
            'casts'         => ['CloseDate' => 'date', 'Amount' => 'float'],
            'relationships' => [],
        ]);

        expect($output)->toContain('protected function casts(): array')
            ->toContain('array_merge(parent::casts()')
            ->toContain("'CloseDate' => 'date'")
            ->toContain("'Amount' => 'float'");
    });

    it('generates model with relationships', function () {
        $generator = new SalesforceModelGenerator();

        $output = $generator->generate([
            'className'     => 'Account',
            'objectName'    => 'Account',
            'namespace'     => 'App\\Models\\Salesforce',
            'fields'        => null,
            'casts'         => [],
            'relationships' => [
                [
                    'type'         => 'belongsTo',
                    'relatedClass' => 'App\\Models\\Salesforce\\ParentAccount',
                    'foreignKey'   => 'ParentId',
                    'methodName'   => 'parent',
                ],
                [
                    'type'         => 'hasMany',
                    'relatedClass' => 'App\\Models\\Salesforce\\Contact',
                    'foreignKey'   => 'AccountId',
                    'methodName'   => 'contacts',
                ],
            ],
        ]);

        expect($output)->toContain('use App\\Models\\Salesforce\\ParentAccount;')
            ->toContain('use App\\Models\\Salesforce\\Contact;')
            ->toContain('public function parent()')
            ->toContain('return $this->belongsTo(ParentAccount::class, \'ParentId\')')
            ->toContain('public function contacts()')
            ->toContain('return $this->hasMany(Contact::class, \'AccountId\')');
    });

    it('generates a fully populated model', function () {
        $generator = new SalesforceModelGenerator();

        $output = $generator->generate([
            'className'     => 'OpportunityProduct',
            'objectName'    => 'Opportunity_Product__c',
            'namespace'     => 'App\\Models\\Salesforce',
            'fields'        => ['Name', 'Amount', 'IsActive'],
            'casts'         => ['Amount' => 'float', 'IsActive' => 'boolean'],
            'relationships' => [
                [
                    'type'         => 'belongsTo',
                    'relatedClass' => 'App\\Models\\Salesforce\\Opportunity',
                    'foreignKey'   => 'Opportunity__c',
                    'methodName'   => 'opportunity',
                ],
            ],
        ]);

        expect($output)->toContain('declare(strict_types=1);')
            ->toContain("protected \$table = 'Opportunity_Product__c';")
            ->toContain('protected ?array $defaultColumns = [')
            ->toContain('protected function casts(): array')
            ->toContain('public function opportunity()');
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/SalesforceModelGeneratorTest.php --filter="generate"`

Expected: FAIL — `generate` method does not exist.

- [ ] **Step 3: Implement the generate method**

Add to `src/Support/SalesforceModelGenerator.php`:

```php
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
 * @return string  Generated PHP file content
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

        $methods[] = <<<PHP
            public function {$method}()
            {
                return \$this->{$type}({$class}::class, '{$foreignKey}');
            }
        PHP;
    }

    return implode("\n\n", $methods) . "\n";
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/SalesforceModelGeneratorTest.php`

Expected: All PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Support/SalesforceModelGenerator.php tests/Unit/SalesforceModelGeneratorTest.php stubs/salesforce-model.stub
git commit -m "feat: add stub rendering to SalesforceModelGenerator"
```

---

### Task 8: Build the Command — Core Flow

**Files:**
- Create: `src/Commands/MakeSalesforceModelCommand.php`
- Modify: `src/EloquentSalesforceObjectsServiceProvider.php:27-29`

- [ ] **Step 1: Create the command class**

Create `src/Commands/MakeSalesforceModelCommand.php`:

```php
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
        // 1. Resolve the Salesforce object name
        $objectName = $this->resolveObjectName();

        if (! $objectName) {
            $this->error('No object selected.');
            return self::FAILURE;
        }

        $this->info("Fetching metadata for {$objectName}...");

        // 2. Fetch describe metadata
        try {
            $metadata = $this->adapter->describe($objectName);
        } catch (Throwable $e) {
            $this->error("Failed to describe {$objectName}: {$e->getMessage()}");
            return self::FAILURE;
        }

        $fields = $metadata['fields'] ?? [];
        $childRelationships = $metadata['childRelationships'] ?? [];

        // 3. Confirm class name
        $suggestedName = SalesforceModelGenerator::resolveClassName($objectName);
        $className = text(
            label: 'Class name for the model',
            default: $suggestedName,
            required: true,
        );

        // 4. Resolve output path and namespace
        $outputPath = $this->option('path') ?? config('eloquent-salesforce-objects.model_generation.path');
        $namespace = config('eloquent-salesforce-objects.model_generation.namespace');

        // Adjust namespace if custom path was provided
        if ($this->option('path')) {
            $namespace = $this->pathToNamespace($outputPath);
        }

        // 5. Field selection
        $selectedFields = $this->selectFields($fields);

        // 6. Cast mapping
        $castMap = config('eloquent-salesforce-objects.model_generation.cast_map', []);
        $fieldsForCasts = $selectedFields === null ? $fields : array_filter(
            $fields,
            fn (array $f): bool => in_array($f['name'] ?? '', $selectedFields, true),
        );
        $casts = $this->generator->buildCasts($fieldsForCasts, $castMap);

        // 7. Relationship selection
        $relationships = $this->selectRelationships($fields, $childRelationships, $namespace, $outputPath);

        // 8. Generate
        $content = $this->generator->generate([
            'className'     => $className,
            'objectName'    => $objectName,
            'namespace'     => $namespace,
            'fields'        => $selectedFields,
            'casts'         => $casts,
            'relationships' => $relationships,
        ]);

        // 9. Write file
        $filePath = rtrim($outputPath, '/') . "/{$className}.php";

        if (! $this->writeFile($filePath, $content)) {
            return self::FAILURE;
        }

        $this->info("Model created: {$filePath}");

        return self::SUCCESS;
    }

    /**
     * Resolve the Salesforce object name from argument or interactive search.
     */
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

    /**
     * Interactive field selection.
     * Returns null for "all fields" or an array of selected field names.
     */
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

        // Separate required and optional fields
        $requiredFields = [];
        $optionalFields = [];

        foreach ($fields as $field) {
            $name = $field['name'] ?? null;
            $nillable = $field['nillable'] ?? true;
            $createable = $field['createable'] ?? false;

            if (! $name || $name === 'Id') {
                continue;
            }

            // Required = createable and not nillable (must have a value)
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

    /**
     * Interactive relationship selection.
     * Only shows relationships where the related model class exists.
     */
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

        // Check which related models exist
        $available = [];
        $unavailable = [];

        foreach ($allRelationships as $rel) {
            $relatedClassName = SalesforceModelGenerator::resolveClassName($rel['relatedObject']);
            $relatedFilePath = rtrim($outputPath, '/') . "/{$relatedClassName}.php";

            if (file_exists($relatedFilePath)) {
                $rel['relatedClass'] = "{$namespace}\\{$relatedClassName}";
                $available[] = $rel;
            } else {
                $unavailable[] = $rel;
            }
        }

        // Show unavailable relationships as info
        foreach ($unavailable as $rel) {
            $this->line("  <fg=gray>{$rel['type']} {$rel['relatedObject']} (via {$rel['foreignKey']}) — model not found, skipping</>");
        }

        if ($available === []) {
            $this->info('No relationships with existing models found.');
            return [];
        }

        // Build options for multiselect
        $options = [];
        foreach ($available as $i => $rel) {
            $options[$i] = "{$rel['type']} {$rel['relatedObject']} (via {$rel['foreignKey']})";
        }

        $selected = multiselect(
            label: 'Select relationships to include',
            options: $options,
        );

        return array_map(fn ($i) => $available[$i], $selected);
    }

    /**
     * Write file with conflict detection.
     */
    private function writeFile(string $filePath, string $content): bool
    {
        // Ensure directory exists
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
                return false;
            }

            if ($action === 'diff') {
                $existing = File::get($filePath);
                $this->line('');
                $this->line('<fg=red>--- Existing</>');
                $this->line('<fg=green>+++ Generated</>');
                $this->line('');

                // Simple line-by-line diff
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
                    return false;
                }
            }
        }

        File::put($filePath, $content);

        return true;
    }

    /**
     * Convert a file path to a PSR-4 namespace.
     */
    private function pathToNamespace(string $path): string
    {
        $appPath = app_path();
        $relativePath = str_replace($appPath, '', $path);
        $namespace = 'App' . str_replace('/', '\\', $relativePath);

        return rtrim($namespace, '\\');
    }
}
```

- [ ] **Step 2: Register the command in the service provider**

In `src/EloquentSalesforceObjectsServiceProvider.php`, update the `hasCommands` array:

```php
->hasCommands([
    EloquentSalesforceObjectsCommand::class,
    \Daikazu\EloquentSalesforceObjects\Commands\MakeSalesforceModelCommand::class,
]);
```

Add the import at the top:

```php
use Daikazu\EloquentSalesforceObjects\Commands\MakeSalesforceModelCommand;
```

And update the `hasCommands` to:

```php
->hasCommands([
    EloquentSalesforceObjectsCommand::class,
    MakeSalesforceModelCommand::class,
]);
```

Also add stub publishing in the `registeringPackage` method (or a new `bootingPackage` method):

```php
public function bootingPackage(): void
{
    if ($this->app->runningInConsole()) {
        $this->publishes([
            __DIR__ . '/../stubs/salesforce-model.stub' => base_path('stubs/salesforce-model.stub'),
        ], 'salesforce-stubs');
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Commands/MakeSalesforceModelCommand.php src/EloquentSalesforceObjectsServiceProvider.php
git commit -m "feat: add MakeSalesforceModelCommand with interactive flow"
```

---

### Task 9: Write Command Integration Tests

**Files:**
- Create: `tests/Unit/MakeSalesforceModelCommandTest.php`

- [ ] **Step 1: Write command tests**

Create `tests/Unit/MakeSalesforceModelCommandTest.php`:

```php
<?php

use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;
use Illuminate\Support\Facades\File;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

beforeEach(function () {
    $forrestMock = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
    $this->app->instance('forrest', $forrestMock);
    Forrest::swap($forrestMock);

    $this->describeResponse = [
        'fields' => [
            ['name' => 'Id', 'type' => 'id', 'createable' => false, 'updateable' => false, 'nillable' => false],
            ['name' => 'Name', 'type' => 'string', 'createable' => true, 'updateable' => true, 'nillable' => false],
            ['name' => 'Industry', 'type' => 'string', 'createable' => true, 'updateable' => true, 'nillable' => true],
            ['name' => 'Website', 'type' => 'string', 'createable' => true, 'updateable' => true, 'nillable' => true],
            ['name' => 'AnnualRevenue', 'type' => 'currency', 'createable' => true, 'updateable' => true, 'nillable' => true],
            ['name' => 'IsActive', 'type' => 'boolean', 'createable' => true, 'updateable' => true, 'nillable' => false],
            ['name' => 'CreatedDate', 'type' => 'datetime', 'createable' => false, 'updateable' => false, 'nillable' => false],
            ['name' => 'AccountId', 'type' => 'reference', 'createable' => true, 'updateable' => true, 'nillable' => true,
                'referenceTo' => ['Account'], 'relationshipName' => 'Account'],
        ],
        'childRelationships' => [
            ['childSObject' => 'Contact', 'field' => 'AccountId', 'relationshipName' => 'Contacts'],
        ],
    ];

    // Set up temp output directory
    $this->outputPath = sys_get_temp_dir() . '/salesforce-model-test-' . uniqid();
    config(['eloquent-salesforce-objects.model_generation.path' => $this->outputPath]);
    config(['eloquent-salesforce-objects.model_generation.namespace' => 'App\\Models\\Salesforce']);
});

afterEach(function () {
    Mockery::close();

    if (is_dir($this->outputPath)) {
        File::deleteDirectory($this->outputPath);
    }
});

describe('make:salesforce-model command', function () {
    it('generates a model with --all-fields and --no-relationships', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn($this->describeResponse);

        $this->artisan('make:salesforce-model', [
            'object' => 'Account',
            '--all-fields' => true,
            '--no-relationships' => true,
            '--force' => true,
        ])
            ->expectsQuestion('Class name for the model', 'Account')
            ->assertSuccessful();

        $filePath = $this->outputPath . '/Account.php';
        expect(file_exists($filePath))->toBeTrue();

        $content = file_get_contents($filePath);
        expect($content)->toContain('class Account extends SalesforceModel')
            ->not->toContain('protected $table')
            ->not->toContain('$defaultColumns');
    });

    it('generates a model for a custom object with correct table property', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('My_Object__c')->andReturn($this->describeResponse);

        $this->artisan('make:salesforce-model', [
            'object' => 'My_Object__c',
            '--all-fields' => true,
            '--no-relationships' => true,
            '--force' => true,
        ])
            ->expectsQuestion('Class name for the model', 'MyObject')
            ->assertSuccessful();

        $filePath = $this->outputPath . '/MyObject.php';
        expect(file_exists($filePath))->toBeTrue();

        $content = file_get_contents($filePath);
        expect($content)->toContain("protected \$table = 'My_Object__c';")
            ->toContain('class MyObject extends SalesforceModel');
    });

    it('does not overwrite existing file without --force', function () {
        // Pre-create the file
        File::ensureDirectoryExists($this->outputPath);
        File::put($this->outputPath . '/Account.php', '<?php // existing');

        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn($this->describeResponse);

        $this->artisan('make:salesforce-model', [
            'object' => 'Account',
            '--all-fields' => true,
            '--no-relationships' => true,
        ])
            ->expectsQuestion('Class name for the model', 'Account')
            ->expectsQuestion("Model file already exists: {$this->outputPath}/Account.php", 'skip')
            ->assertSuccessful();

        // File should not have been overwritten
        $content = file_get_contents($this->outputPath . '/Account.php');
        expect($content)->toBe('<?php // existing');
    });
});
```

- [ ] **Step 2: Run the tests**

Run: `./vendor/bin/pest tests/Unit/MakeSalesforceModelCommandTest.php`

Expected: All PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/MakeSalesforceModelCommandTest.php
git commit -m "test: add MakeSalesforceModelCommand integration tests"
```

---

### Task 10: Final Verification

- [ ] **Step 1: Run full test suite**

Run: `./vendor/bin/pest`

Expected: All tests pass.

- [ ] **Step 2: Verify the command is discoverable**

Run: `php artisan list | grep salesforce`

Expected: `make:salesforce-model` appears in the command list.

- [ ] **Step 3: Final commit if any cleanup needed**

```bash
git add -A
git commit -m "chore: final cleanup for make:salesforce-model feature"
```
