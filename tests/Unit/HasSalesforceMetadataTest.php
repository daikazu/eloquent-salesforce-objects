<?php

use Daikazu\EloquentSalesforceObjects\Contracts\AdapterInterface;
use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

beforeEach(function () {
    $forrestMock = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
    $this->app->instance('forrest', $forrestMock);
    Forrest::swap($forrestMock);

    $this->mockAdapter = Mockery::mock(AdapterInterface::class);
    $this->app->instance(AdapterInterface::class, $this->mockAdapter);

    $this->account = new Account;
});

afterEach(function () {
    Mockery::close();
});

describe('HasSalesforceMetadata', function () {
    describe('picklistValues()', function () {
        it('delegates to the adapter with the correct object name and field', function () {
            $expected = [
                ['value' => 'Technology', 'label' => 'Technology'],
                ['value' => 'Finance', 'label' => 'Finance'],
            ];

            $this->mockAdapter
                ->shouldReceive('picklistValues')
                ->once()
                ->with('Account', 'Industry')
                ->andReturn($expected);

            $result = $this->account->picklistValues('Industry');

            expect($result)->toBe($expected);
        });

        it('returns an empty array when the adapter returns no values', function () {
            $this->mockAdapter
                ->shouldReceive('picklistValues')
                ->once()
                ->with('Account', 'EmptyField__c')
                ->andReturn([]);

            $result = $this->account->picklistValues('EmptyField__c');

            expect($result)->toBe([]);
        });

        it('can be called statically', function () {
            $expected = [
                ['value' => 'Technology', 'label' => 'Technology'],
                ['value' => 'Finance', 'label' => 'Finance'],
            ];

            $this->mockAdapter
                ->shouldReceive('picklistValues')
                ->once()
                ->with('Account', 'Industry')
                ->andReturn($expected);

            $result = Account::picklistValues('Industry');

            expect($result)->toBe($expected);
        });
    });

    describe('describe()', function () {
        it('delegates to the adapter with the correct object name', function () {
            $expected = [
                'name'   => 'Account',
                'fields' => [
                    ['name' => 'Id', 'type' => 'id'],
                    ['name' => 'Name', 'type' => 'string'],
                ],
            ];

            $this->mockAdapter
                ->shouldReceive('describe')
                ->once()
                ->with('Account')
                ->andReturn($expected);

            $result = $this->account->describe();

            expect($result)->toBe($expected);
        });

        it('returns the full describe payload from the adapter', function () {
            $expected = [
                'name'        => 'Account',
                'label'       => 'Account',
                'fields'      => [],
                'recordTypes' => [],
            ];

            $this->mockAdapter
                ->shouldReceive('describe')
                ->once()
                ->with('Account')
                ->andReturn($expected);

            $result = $this->account->describe();

            expect($result)->toHaveKeys(['name', 'label', 'fields', 'recordTypes']);
        });

        it('can be called statically', function () {
            $expected = [
                'name'   => 'Account',
                'fields' => [
                    ['name' => 'Id', 'type' => 'id'],
                    ['name' => 'Name', 'type' => 'string'],
                ],
            ];

            $this->mockAdapter
                ->shouldReceive('describe')
                ->once()
                ->with('Account')
                ->andReturn($expected);

            $result = Account::describe();

            expect($result)->toBe($expected);
        });
    });

    describe('fieldMetadata()', function () {
        it('returns the matching field array from describe results', function () {
            $this->mockAdapter
                ->shouldReceive('describe')
                ->once()
                ->with('Account')
                ->andReturn([
                    'fields' => [
                        ['name' => 'Id', 'type' => 'id', 'label' => 'Account ID'],
                        ['name' => 'Name', 'type' => 'string', 'label' => 'Account Name'],
                        ['name' => 'Industry', 'type' => 'picklist', 'label' => 'Industry'],
                    ],
                ]);

            $result = $this->account->fieldMetadata('Industry');

            expect($result)->toBe(['name' => 'Industry', 'type' => 'picklist', 'label' => 'Industry']);
        });

        it('returns null when the field does not exist in describe results', function () {
            $this->mockAdapter
                ->shouldReceive('describe')
                ->once()
                ->with('Account')
                ->andReturn([
                    'fields' => [
                        ['name' => 'Id', 'type' => 'id', 'label' => 'Account ID'],
                        ['name' => 'Name', 'type' => 'string', 'label' => 'Account Name'],
                    ],
                ]);

            $result = $this->account->fieldMetadata('NonExistentField__c');

            expect($result)->toBeNull();
        });

        it('returns null when describe result has no fields key', function () {
            $this->mockAdapter
                ->shouldReceive('describe')
                ->once()
                ->with('Account')
                ->andReturn([]);

            $result = $this->account->fieldMetadata('Name');

            expect($result)->toBeNull();
        });

        it('returns null when describe result has an empty fields array', function () {
            $this->mockAdapter
                ->shouldReceive('describe')
                ->once()
                ->with('Account')
                ->andReturn(['fields' => []]);

            $result = $this->account->fieldMetadata('Name');

            expect($result)->toBeNull();
        });

        it('returns only the first matching field when names are duplicated', function () {
            $first = ['name' => 'Name', 'type' => 'string', 'label' => 'Account Name'];
            $second = ['name' => 'Name', 'type' => 'textarea', 'label' => 'Account Name (alt)'];

            $this->mockAdapter
                ->shouldReceive('describe')
                ->once()
                ->with('Account')
                ->andReturn(['fields' => [$first, $second]]);

            $result = $this->account->fieldMetadata('Name');

            expect($result)->toBe($first);
        });

        it('can be called statically', function () {
            $this->mockAdapter
                ->shouldReceive('describe')
                ->once()
                ->with('Account')
                ->andReturn([
                    'fields' => [
                        ['name' => 'Id', 'type' => 'id', 'label' => 'Account ID'],
                        ['name' => 'Name', 'type' => 'string', 'label' => 'Account Name'],
                    ],
                ]);

            $result = Account::fieldMetadata('Name');

            expect($result)->toBe(['name' => 'Name', 'type' => 'string', 'label' => 'Account Name']);
        });
    });
});
