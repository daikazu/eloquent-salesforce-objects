# Model Generator

Generate Salesforce model classes from live object metadata using the artisan command.

## Table of Contents

- [Basic Usage](#basic-usage)
- [Interactive Flow](#interactive-flow)
- [Command Options](#command-options)
- [What Gets Generated](#what-gets-generated)
- [Configuration](#configuration)
- [Customizing the Stub](#customizing-the-stub)
- [Tips](#tips)

## Basic Usage

```bash
php artisan make:salesforce-model
```

The command connects to your Salesforce org via the configured Forrest credentials, fetches the object's describe metadata, and generates a fully configured `SalesforceModel` subclass.

You can also pass the object name directly:

```bash
php artisan make:salesforce-model Account
```

## Interactive Flow

When run, the command walks you through each step:

### 1. Object Selection

If no object name is provided as an argument, the command fetches all available objects from your Salesforce org and presents a searchable list:

```
 Search for a Salesforce object
 Type to search (e.g. Account, Opportunity)
```

### 2. Class Name

The command auto-suggests a clean PascalCase class name based on the Salesforce API name:

| Salesforce API Name | Suggested Class Name |
|---|---|
| `Account` | `Account` |
| `Opportunity_Product__c` | `OpportunityProduct` |
| `My_Custom_Object__c` | `MyCustomObject` |

You can accept the suggestion or type a custom name.

### 3. Field Selection

Choose how to handle `$defaultColumns`:

- **All fields** - Sets `$defaultColumns = null`, which selects `*` in queries
- **Select specific fields** - Shows a multi-select list of all fields. Required fields (createable and non-nullable) are always included automatically.

When you select specific fields, any foreign key columns needed by your chosen relationships (e.g., `AccountId` for a `belongsTo Account`) are automatically added.

### 4. Relationship Selection

The command detects relationships from the object's metadata:

- **belongsTo** - Detected from reference fields (e.g., `AccountId` referencing `Account`)
- **hasMany** - Detected from child relationships (e.g., `Contacts` on `Account`)

Polymorphic references (fields like `WhoId` that reference multiple objects) are skipped.

All relationships are selectable regardless of whether the related model exists yet. Relationships to models that haven't been generated are marked with "model not yet generated" and get a TODO comment in the generated code.

### 5. File Conflict Handling

If the target model file already exists, you'll be prompted with three options:

- **Overwrite** - Replace the existing file
- **Show diff** - Display what would change, then confirm
- **Skip** - Cancel generation for this model

## Command Options

```bash
php artisan make:salesforce-model {object?} {--path=} {--all-fields} {--no-relationships} {--force}
```

| Option | Description |
|---|---|
| `object` | Salesforce object API name (e.g., `Account`, `My_Object__c`). If omitted, shows a searchable list. |
| `--path` | Override the output directory for this run. |
| `--all-fields` | Skip field selection, use all fields (`$defaultColumns = null`). |
| `--no-relationships` | Skip relationship detection entirely. |
| `--force` | Overwrite existing model files without prompting. |

### Examples

```bash
# Interactive mode — search and select an object
php artisan make:salesforce-model

# Generate a specific object with all fields
php artisan make:salesforce-model Account --all-fields

# Quick generation, no prompts for relationships
php artisan make:salesforce-model Contact --all-fields --no-relationships --force

# Custom output directory
php artisan make:salesforce-model Account --path=app/Models/CRM
```

## What Gets Generated

A typical generated model looks like this:

```php
<?php

declare(strict_types=1);

namespace App\Models\Salesforce;

use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;
use App\Models\Salesforce\Account;

class Contact extends SalesforceModel
{
    protected ?array $defaultColumns = [
        'FirstName',
        'LastName',
        'Email',
        'Phone',
        'AccountId',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'Birthdate' => 'date',
            'DoNotCall' => 'boolean',
        ]);
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'AccountId');
    }

    // TODO: Generate Lead model — php artisan make:salesforce-model Lead
    public function lead()
    {
        return $this->belongsTo(Lead::class, 'LeadId');
    }
}
```

### What each section includes

- **`$table`** - Only set when the class name differs from the Salesforce API name (e.g., `OpportunityProduct` for `Opportunity_Product__c`)
- **`$defaultColumns`** - The fields you selected, or omitted entirely when using all fields
- **`casts()`** - Auto-mapped from Salesforce field types (datetime, boolean, float, etc.), merged with `parent::casts()`. System timestamp fields (`CreatedDate`, `LastModifiedDate`, `SystemModstamp`, `LastViewedDate`, `LastReferencedDate`) are handled by the parent model and excluded.
- **Relationships** - Methods for each selected relationship with correct foreign keys. Relationships to non-existent models include a TODO comment with the command to generate them.

## Configuration

The generator is configured in `config/eloquent-salesforce-objects.php` under the `model_generation` key:

```php
'model_generation' => [
    // Default output directory
    'path' => app_path('Models/Salesforce'),

    // Default namespace for generated models
    'namespace' => 'App\\Models\\Salesforce',

    // Salesforce field type to Laravel cast mapping
    'cast_map' => [
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

You can customize the `cast_map` to change how Salesforce field types are cast. For example, to use a custom Money value object for currency fields:

```php
'cast_map' => [
    // ...
    'currency' => \App\Casts\Money::class,
],
```

## Customizing the Stub

Publish the stub template to customize the generated model format:

```bash
php artisan vendor:publish --tag=salesforce-stubs
```

This copies the template to `stubs/salesforce-model.stub`. The available placeholders are:

| Placeholder | Description |
|---|---|
| `{{ namespace }}` | Model namespace |
| `{{ className }}` | Model class name |
| `{{ imports }}` | `use` statements for related model classes |
| `{{ table }}` | `$table` property (if needed) |
| `{{ defaultColumns }}` | `$defaultColumns` property (if specific fields selected) |
| `{{ casts }}` | `casts()` method (if fields need casting) |
| `{{ relationships }}` | Relationship methods |

## Tips

- **Generate order doesn't matter** - You can generate models in any order. Relationships to models that don't exist yet will work once those models are generated.
- **Re-generate to update** - If your Salesforce schema changes, re-run the command with `--force` to regenerate the model.
- **Required fields are protected** - When selecting specific fields, required fields (createable and non-nullable) are always included and cannot be deselected.
- **Foreign keys auto-included** - When you select a `belongsTo` relationship and have chosen specific fields, the foreign key column is automatically added to `$defaultColumns`.
