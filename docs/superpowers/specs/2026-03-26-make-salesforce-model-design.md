# Design: `make:salesforce-model` Artisan Command

## Overview

An artisan command that scaffolds Salesforce model classes from live Salesforce metadata. It connects to the Salesforce API via the already-configured Forrest package, fetches object describe metadata, and generates a fully-populated `SalesforceModel` subclass with fields, casts, and relationships.

## Architecture

**Approach: Command + Generator Service**

Two classes with separated concerns:

- `MakeSalesforceModelCommand` — handles all interactive CLI flow (prompts, selection, file conflict resolution)
- `SalesforceModelGenerator` — pure service that takes structured input and returns generated model content as a string

The command orchestrates the user interaction, the generator handles rendering. The generator is independently testable and reusable.

## Command

### Signature

```
php artisan make:salesforce-model {object?} {--path=} {--all-fields} {--no-relationships} {--force}
```

| Argument/Option | Description |
|---|---|
| `{object?}` | Salesforce object API name (e.g., `Account`, `My_Object__c`). If omitted, presents a searchable list via `describeGlobal()`. |
| `--path` | One-off override for output directory. Overrides config default. |
| `--all-fields` | Skip field selection prompt, use `$defaultColumns = null` (select `*`). |
| `--no-relationships` | Skip relationship detection and selection. |
| `--force` | Overwrite existing model file without prompting. |

### Interactive Flow

1. **Authenticate** — Forrest credentials are already configured per package install instructions.
2. **Object selection** — if no argument provided, fetch `describeGlobal()` and present a searchable list using Laravel Prompts `search()`.
3. **Class name confirmation** — auto-suggest clean PascalCase name (see Name Resolution), user can override.
4. **Field selection** — prompt "All fields or select specific?" If specific, show multi-select with required fields pre-checked and locked (cannot be deselected).
5. **Relationship selection** — show detected relationships as a multi-select. Only relationships where the related model class already exists in the configured path are selectable. Others are shown greyed out with a note.
6. **File conflict check** — if target file exists: prompt with Overwrite / Show diff / Skip. `--force` flag skips this prompt.
7. **Generate and write** — call the generator, write the file, confirm success.

## Generator Service: `SalesforceModelGenerator`

### Input

```php
SalesforceModelGenerator::generate([
    'className'      => 'OpportunityProduct',
    'objectName'     => 'Opportunity_Product__c',
    'namespace'      => 'App\\Models\\Salesforce',
    'fields'         => ['Name', 'Amount', ...],    // null = all fields (*)
    'requiredFields' => ['Name'],
    'casts'          => ['CloseDate' => 'datetime', 'IsActive' => 'boolean'],
    'relationships'  => [
        ['type' => 'belongsTo', 'related' => 'App\\Models\\Salesforce\\Account', 'foreignKey' => 'AccountId'],
        ['type' => 'hasMany', 'related' => 'App\\Models\\Salesforce\\Contact', 'foreignKey' => 'OpportunityId'],
    ],
]);
```

### Responsibilities

- Render model from stub template
- Set `$table` only when class name does not match Salesforce API name
- Set `$defaultColumns` from selected fields, or omit entirely when null (all fields)
- Generate `casts()` method using `array_merge(parent::casts(), [...])`, excluding fields already cast by the parent (`CreatedDate`, `LastModifiedDate`, `SystemModstamp`, `LastViewedDate`, `LastReferencedDate`)
- Generate relationship methods with correct return types (`SOQLHasMany`, `SOQLHasOne`, `BelongsTo`)
- Return generated file content as a string (command handles file writing)

### Name Resolution

Static helper method that converts Salesforce API names to PHP class names:

| Salesforce API Name | Generated Class Name | `$table` set? |
|---|---|---|
| `Account` | `Account` | No |
| `Opportunity_Product__c` | `OpportunityProduct` | Yes (`Opportunity_Product__c`) |
| `My_Custom_Object__c` | `MyCustomObject` | Yes (`My_Custom_Object__c`) |
| `Contact` | `Contact` | No |

Rules: strip `__c` suffix, convert underscores to PascalCase. Standard objects pass through unchanged.

## Stub Template

Located at `stubs/salesforce-model.stub`, publishable via `vendor:publish`.

```php
<?php

declare(strict_types=1);

namespace {{ namespace }};

use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;
{{ imports }}

class {{ className }} extends SalesforceModel
{
{{ table }}
{{ defaultColumns }}
{{ casts }}
{{ relationships }}
}
```

### Placeholder Rules

- `{{ table }}` — only rendered if class name differs from Salesforce API name
- `{{ defaultColumns }}` — rendered as array, or omitted entirely when null
- `{{ casts }}` — only rendered when there are casts beyond parent defaults
- `{{ relationships }}` — one method per selected relationship
- `{{ imports }}` — `use` statements for related model classes

## Relationship Detection

### belongsTo

Detected from fields where `type = 'reference'` and `referenceTo` is populated:

```json
{
    "name": "AccountId",
    "type": "reference",
    "referenceTo": ["Account"],
    "relationshipName": "Account"
}
```

Generates:
```php
public function account(): \Illuminate\Database\Eloquent\Relations\BelongsTo
{
    return $this->belongsTo(Account::class, 'AccountId');
}
```

### hasMany

Detected from `childRelationships` in the describe response:

```json
{
    "childSObject": "Contact",
    "field": "AccountId",
    "relationshipName": "Contacts"
}
```

Generated as `hasMany` by default. `hasOne` is rare and not reliably detectable from metadata.

### Selection UI

```
Detected relationships:
  [ ] belongsTo Account (via AccountId)
  [ ] hasMany Contact (via AccountId)
  [ ] hasMany Opportunity (via AccountId)
      hasMany Case (via AccountId)  -- Model not found, skipping
```

Only relationships where the related model class exists in the configured path are selectable. Others are shown as informational.

Method names derived from Salesforce relationship names: singular for belongsTo, plural for hasMany.

### Polymorphic References

Some Salesforce lookup fields reference multiple objects (e.g., `WhoId` references both `Contact` and `Lead`). These polymorphic lookups (`referenceTo` array with multiple entries) are skipped in v1 — they require a different pattern than a simple `belongsTo`. They are shown in the relationship list as informational with a "polymorphic, skipping" note.

## Config Additions

Added to `config/eloquent-salesforce-objects.php`:

```php
'model_generation' => [
    // Default path for generated Salesforce models
    'path' => app_path('Models/Salesforce'),

    // Default namespace for generated models
    'namespace' => 'App\\Models\\Salesforce',

    // Salesforce field type to Laravel cast mapping
    'cast_map' => [
        'datetime'  => 'datetime',
        'date'      => 'date',
        'boolean'   => 'boolean',
        'double'    => 'float',
        'currency'  => 'float',
        'percent'   => 'float',
        'int'       => 'integer',
    ],
],
```

## Parent Model Changes

Add common Salesforce system timestamp fields to `SalesforceModel::casts()`:

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

The generator excludes these from generated `casts()` methods since the parent handles them.

## File Conflict Handling

When the target model file already exists:

- **Overwrite** — replace the file entirely. `--force` flag does this silently.
- **Show diff** — print a unified diff of existing vs. generated content, then re-prompt with Overwrite/Skip.
- **Skip** — cancel generation for this model, no changes.

No auto-merge. Users can use the diff output to manually merge if they've customized their model.

## File Structure

```
src/
  Commands/
    MakeSalesforceModelCommand.php    # Interactive CLI flow
  Support/
    SalesforceModelGenerator.php      # Pure generation logic
stubs/
  salesforce-model.stub               # Publishable template
config/
  eloquent-salesforce-objects.php      # + model_generation section
src/Models/
  SalesforceModel.php                 # + additional system field casts
```

## Out of Scope (v1)

- Smart merging of existing model files (overwrite or skip only)
- Auto-generating stub models for related objects that don't exist yet
- Batch generation of multiple models in one command invocation
- Offline mode (cached describe JSON) — requires live Salesforce connection
