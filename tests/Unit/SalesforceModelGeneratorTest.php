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
