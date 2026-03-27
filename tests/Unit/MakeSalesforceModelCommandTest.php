<?php

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
            ['name'           => 'AccountId', 'type' => 'reference', 'createable' => true, 'updateable' => true, 'nillable' => true,
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
    // Disable metadata cache so describe calls go directly to Forrest
    config(['eloquent-salesforce-objects.metadata_cache_ttl' => 0]);
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
            'object'             => 'Account',
            '--all-fields'       => true,
            '--no-relationships' => true,
            '--force'            => true,
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
            'object'             => 'My_Object__c',
            '--all-fields'       => true,
            '--no-relationships' => true,
            '--force'            => true,
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
        File::ensureDirectoryExists($this->outputPath);
        File::put($this->outputPath . '/Account.php', '<?php // existing');

        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn($this->describeResponse);

        $this->artisan('make:salesforce-model', [
            'object'             => 'Account',
            '--all-fields'       => true,
            '--no-relationships' => true,
        ])
            ->expectsQuestion('Class name for the model', 'Account')
            ->expectsQuestion("Model file already exists: {$this->outputPath}/Account.php", 'skip')
            ->assertSuccessful();

        $content = file_get_contents($this->outputPath . '/Account.php');
        expect($content)->toBe('<?php // existing');
    });
});
