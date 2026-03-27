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
        $generator = new SalesforceModelGenerator;
        $casts = $generator->buildCasts($fields, $castMap);

        expect($casts)->toBe([
            'CloseDate' => 'date',
            'IsActive'  => 'boolean',
            'Amount'    => 'float',
            'Count'     => 'integer',
        ]);
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
        $generator = new SalesforceModelGenerator;
        $casts = $generator->buildCasts($fields, $castMap);

        expect($casts)->toBe(['CustomDate__c' => 'datetime']);
    });

    it('returns empty array when no fields need casting', function () {
        $fields = [
            ['name' => 'Name', 'type' => 'string', 'createable' => true, 'updateable' => true],
            ['name' => 'Description', 'type' => 'textarea', 'createable' => true, 'updateable' => true],
        ];

        $castMap = config('eloquent-salesforce-objects.model_generation.cast_map');
        $generator = new SalesforceModelGenerator;
        $casts = $generator->buildCasts($fields, $castMap);

        expect($casts)->toBe([]);
    });
});

describe('relationship extraction', function () {
    it('extracts belongsTo from reference fields', function () {
        $fields = [
            [
                'name'             => 'AccountId',
                'type'             => 'reference',
                'referenceTo'      => ['Account'],
                'relationshipName' => 'Account',
                'createable'       => true,
                'updateable'       => true,
            ],
            [
                'name'       => 'Name',
                'type'       => 'string',
                'createable' => true,
                'updateable' => true,
            ],
        ];

        $generator = new SalesforceModelGenerator;
        $relationships = $generator->extractBelongsToRelationships($fields);

        expect($relationships)->toHaveCount(1);
        expect($relationships[0])->toBe([
            'type'          => 'belongsTo',
            'relatedObject' => 'Account',
            'foreignKey'    => 'AccountId',
            'methodName'    => 'account',
        ]);
    });

    it('skips polymorphic references', function () {
        $fields = [
            [
                'name'             => 'WhoId',
                'type'             => 'reference',
                'referenceTo'      => ['Contact', 'Lead'],
                'relationshipName' => 'Who',
                'createable'       => true,
                'updateable'       => true,
            ],
        ];

        $generator = new SalesforceModelGenerator;
        $relationships = $generator->extractBelongsToRelationships($fields);

        expect($relationships)->toHaveCount(0);
    });

    it('extracts hasMany from childRelationships', function () {
        $childRelationships = [
            [
                'childSObject'     => 'Contact',
                'field'            => 'AccountId',
                'relationshipName' => 'Contacts',
            ],
            [
                'childSObject'     => 'Opportunity',
                'field'            => 'AccountId',
                'relationshipName' => 'Opportunities',
            ],
        ];

        $generator = new SalesforceModelGenerator;
        $relationships = $generator->extractHasManyRelationships($childRelationships);

        expect($relationships)->toHaveCount(2);
        expect($relationships[0])->toBe([
            'type'          => 'hasMany',
            'relatedObject' => 'Contact',
            'foreignKey'    => 'AccountId',
            'methodName'    => 'contacts',
        ]);
    });

    it('skips childRelationships with null relationshipName', function () {
        $childRelationships = [
            [
                'childSObject'     => 'ContactHistory',
                'field'            => 'ContactId',
                'relationshipName' => null,
            ],
        ];

        $generator = new SalesforceModelGenerator;
        $relationships = $generator->extractHasManyRelationships($childRelationships);

        expect($relationships)->toHaveCount(0);
    });
});

describe('generate', function () {
    it('generates a basic model with no custom fields or relationships', function () {
        $generator = new SalesforceModelGenerator;

        $output = $generator->generate([
            'className'     => 'Account',
            'objectName'    => 'Account',
            'namespace'     => 'App\\Models\\Salesforce',
            'fields'        => null,
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
        $generator = new SalesforceModelGenerator;

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
        $generator = new SalesforceModelGenerator;

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
        $generator = new SalesforceModelGenerator;

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
        $generator = new SalesforceModelGenerator;

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
        $generator = new SalesforceModelGenerator;

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
