<?php

use Daikazu\EloquentSalesforceObjects\Support\SalesforceHelper;

describe('SalesforceHelper::isValidId()', function () {

    describe('invalid inputs', function () {
        it('returns false for null', function () {
            expect(SalesforceHelper::isValidId(null))->toBeFalse();
        });

        it('returns false for an empty string', function () {
            expect(SalesforceHelper::isValidId(''))->toBeFalse();
        });

        it('returns false for a 14-character string (too short)', function () {
            expect(SalesforceHelper::isValidId('001xx000003DGb'))->toBeFalse();
        });

        it('returns false for a 16-character string (wrong length)', function () {
            expect(SalesforceHelper::isValidId('001xx000003DGb2A'))->toBeFalse();
        });

        it('returns false for a 17-character string (wrong length)', function () {
            expect(SalesforceHelper::isValidId('001xx000003DGb2AA'))->toBeFalse();
        });

        it('returns false for a string containing spaces', function () {
            // After trimming this becomes 13 characters — still invalid
            expect(SalesforceHelper::isValidId(' 001xx000003D '))->toBeFalse();
        });

        it('returns false for a 15-character string with non-alphanumeric characters', function () {
            expect(SalesforceHelper::isValidId('001xx000003DG-2'))->toBeFalse();
        });

        it('returns false for an 18-character string with non-alphanumeric characters', function () {
            expect(SalesforceHelper::isValidId('001xx000003DGb2!AG'))->toBeFalse();
        });

        it('returns false for an 18-character string with an incorrect checksum', function () {
            // Base: 001xx000003DGb2, correct checksum: AAG, wrong: ZZZ
            expect(SalesforceHelper::isValidId('001xx000003DGb2ZZZ'))->toBeFalse();
        });

        it('returns false for an 18-character string where only one checksum character is wrong', function () {
            // Correct checksum is AAG; changing one character makes it invalid
            expect(SalesforceHelper::isValidId('001xx000003DGb2BAG'))->toBeFalse();
        });
    });

    describe('valid inputs', function () {
        it('returns true for a 15-character alphanumeric string', function () {
            expect(SalesforceHelper::isValidId('001xx000003DGb2'))->toBeTrue();
        });

        it('returns true for a 15-character all-lowercase alphanumeric string', function () {
            expect(SalesforceHelper::isValidId('001xx000003dgb2'))->toBeTrue();
        });

        it('returns true for an 18-character string with a correct checksum', function () {
            expect(SalesforceHelper::isValidId('001xx000003DGb2AAG'))->toBeTrue();
        });

        it('accepts the real 15-character Salesforce ID 001xx000003DGb2', function () {
            expect(SalesforceHelper::isValidId('001xx000003DGb2'))->toBeTrue();
        });

        it('accepts the real 18-character Salesforce ID 001xx000003DGb2AAG', function () {
            expect(SalesforceHelper::isValidId('001xx000003DGb2AAG'))->toBeTrue();
        });

        it('trims surrounding whitespace before validating a 15-character ID', function () {
            expect(SalesforceHelper::isValidId('  001xx000003DGb2  '))->toBeTrue();
        });

        it('trims surrounding whitespace before validating an 18-character ID', function () {
            expect(SalesforceHelper::isValidId('  001xx000003DGb2AAG  '))->toBeTrue();
        });
    });

});

describe('SalesforceHelper::getUpdatableFieldNames()', function () {

    it('returns an empty array when passed an empty fields list', function () {
        expect(SalesforceHelper::getUpdatableFieldNames([]))->toBe([]);
    });

    it('returns an empty array when no fields are updateable', function () {
        $fields = [
            ['name' => 'Id', 'updateable' => false],
            ['name' => 'CreatedDate', 'updateable' => false],
            ['name' => 'SystemModstamp', 'updateable' => false],
        ];

        expect(SalesforceHelper::getUpdatableFieldNames($fields))->toBe([]);
    });

    it('extracts names of updateable fields in flat format', function () {
        $fields = [
            ['name' => 'Id', 'updateable' => false],
            ['name' => 'Name', 'updateable' => true],
            ['name' => 'Industry', 'updateable' => true],
            ['name' => 'CreatedDate', 'updateable' => false],
        ];

        $result = SalesforceHelper::getUpdatableFieldNames($fields);

        expect($result)->toBe(['Name', 'Industry']);
    });

    it('extracts names from nested details format', function () {
        $fields = [
            ['details' => ['name' => 'Name', 'updateable' => true]],
            ['details' => ['name' => 'Id', 'updateable' => false]],
            ['details' => ['name' => 'BillingCity', 'updateable' => true]],
        ];

        $result = SalesforceHelper::getUpdatableFieldNames($fields);

        expect($result)->toBe(['Name', 'BillingCity']);
    });

    it('prefers details format over flat format when both are present', function () {
        $fields = [
            [
                'name'       => 'FlatName',
                'updateable' => false,
                'details'    => ['name' => 'DetailsName', 'updateable' => true],
            ],
        ];

        $result = SalesforceHelper::getUpdatableFieldNames($fields);

        expect($result)->toContain('DetailsName');
        expect($result)->not->toContain('FlatName');
    });

    it('skips fields with a missing name key', function () {
        $fields = [
            ['updateable' => true],
            ['name' => 'ValidField', 'updateable' => true],
        ];

        $result = SalesforceHelper::getUpdatableFieldNames($fields);

        expect($result)->toBe(['ValidField']);
    });

    it('skips fields with a missing updateable key', function () {
        $fields = [
            ['name' => 'NoUpdateableKey'],
            ['name' => 'ValidField', 'updateable' => true],
        ];

        $result = SalesforceHelper::getUpdatableFieldNames($fields);

        expect($result)->toBe(['ValidField']);
    });

    it('recursively extracts updateable fields from nested components', function () {
        $fields = [
            [
                'name'       => 'BillingAddress',
                'updateable' => false,
                'components' => [
                    ['name' => 'BillingStreet', 'updateable' => true],
                    ['name' => 'BillingCity', 'updateable' => true],
                    ['name' => 'BillingState', 'updateable' => true],
                    ['name' => 'BillingPostalCode', 'updateable' => true],
                    ['name' => 'BillingCountry', 'updateable' => true],
                ],
            ],
        ];

        $result = SalesforceHelper::getUpdatableFieldNames($fields);

        expect($result)->toBe([
            'BillingStreet',
            'BillingCity',
            'BillingState',
            'BillingPostalCode',
            'BillingCountry',
        ]);
        expect($result)->not->toContain('BillingAddress');
    });

    it('includes the parent field name and its updateable components when both are updateable', function () {
        $fields = [
            [
                'name'       => 'ParentField',
                'updateable' => true,
                'components' => [
                    ['name' => 'ChildField', 'updateable' => true],
                    ['name' => 'ReadOnlyChild', 'updateable' => false],
                ],
            ],
        ];

        $result = SalesforceHelper::getUpdatableFieldNames($fields);

        expect($result)->toContain('ParentField', 'ChildField');
        expect($result)->not->toContain('ReadOnlyChild');
    });

    it('handles deeply nested components recursively', function () {
        $fields = [
            [
                'name'       => 'Level1',
                'updateable' => false,
                'components' => [
                    [
                        'name'       => 'Level2',
                        'updateable' => false,
                        'components' => [
                            ['name' => 'Level3', 'updateable' => true],
                        ],
                    ],
                ],
            ],
        ];

        $result = SalesforceHelper::getUpdatableFieldNames($fields);

        expect($result)->toBe(['Level3']);
    });

    it('accumulates results across multiple top-level fields', function () {
        $fields = [
            ['name' => 'Name', 'updateable' => true],
            [
                'name'       => 'BillingAddress',
                'updateable' => false,
                'components' => [
                    ['name' => 'BillingCity', 'updateable' => true],
                ],
            ],
            ['name' => 'Industry', 'updateable' => true],
        ];

        $result = SalesforceHelper::getUpdatableFieldNames($fields);

        expect($result)->toBe(['Name', 'BillingCity', 'Industry']);
    });

    it('ignores a components key whose value is not an array', function () {
        $fields = [
            ['name' => 'Name', 'updateable' => true, 'components' => 'not-an-array'],
        ];

        $result = SalesforceHelper::getUpdatableFieldNames($fields);

        expect($result)->toBe(['Name']);
    });

});
