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
        $generator = new SalesforceModelGenerator();
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
