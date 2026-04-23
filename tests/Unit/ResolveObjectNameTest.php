<?php

declare(strict_types=1);

use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Daikazu\EloquentSalesforceObjects\Examples\Contact;
use Daikazu\EloquentSalesforceObjects\Exceptions\SalesforceException;
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;
use Daikazu\EloquentSalesforceObjects\Tests\Unit\Fixtures\AccountWithExplicitTable;
use Mockery\Mock;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

// Baseline describe response shared across tests
function minimalDescribeResponse(): array
{
    return [
        'name'   => 'Account',
        'fields' => [
            ['name' => 'Id', 'type' => 'id'],
            ['name' => 'Name', 'type' => 'string'],
        ],
    ];
}

function picklistDescribeResponse(): array
{
    return [
        'name'   => 'Account',
        'fields' => [
            ['name' => 'Id', 'type' => 'id'],
            [
                'name'           => 'Industry',
                'type'           => 'picklist',
                'picklistValues' => [
                    ['value' => 'Technology', 'label' => 'Technology', 'active' => true, 'defaultValue' => false],
                    ['value' => 'Finance', 'label' => 'Finance', 'active' => true, 'defaultValue' => true],
                    ['value' => 'Retired', 'label' => 'Retired Industry', 'active' => false, 'defaultValue' => false],
                ],
            ],
            [
                'name'           => 'Channels__c',
                'type'           => 'multipicklist',
                'picklistValues' => [
                    ['value' => 'Email', 'label' => 'Email', 'active' => true, 'defaultValue' => false],
                    ['value' => 'Phone', 'label' => 'Phone', 'active' => true, 'defaultValue' => false],
                ],
            ],
            [
                'name' => 'Name',
                'type' => 'string',
            ],
        ],
    ];
}

beforeEach(function () {
    $forrestMock = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
    $this->app->instance('forrest', $forrestMock);
    Forrest::swap($forrestMock);
    Forrest::shouldReceive('hasToken')->andReturn(true);

    // Disable metadata cache so all describe calls pass through to the Forrest mock
    config(['eloquent-salesforce-objects.metadata_cache_ttl' => 0]);

    $this->adapter = app(SalesforceAdapter::class);
});

afterEach(function () {
    Mockery::close();
});

describe('resolveObjectName (via describe())', function () {
    it('passes a plain string object name directly to Forrest::describe', function () {
        Forrest::shouldReceive('describe')
            ->once()
            ->with('Account')
            ->andReturn(minimalDescribeResponse());

        $result = $this->adapter->describe('Account');

        expect($result)->toHaveKey('name');
        expect($result['name'])->toBe('Account');
    });

    it('passes null to performDescribe when given null', function () {
        // Null bypasses the cache branch entirely; performDescribe is called with null
        Forrest::shouldReceive('describe')
            ->once()
            ->with(null)
            ->andReturn(['sobjects' => []]);

        $result = $this->adapter->describe(null);

        expect($result)->toBeArray();
    });

    it('resolves a SalesforceModel instance to its table name', function () {
        Forrest::shouldReceive('describe')
            ->once()
            ->with('Account')
            ->andReturn(minimalDescribeResponse());

        $result = $this->adapter->describe(new Account);

        expect($result['name'])->toBe('Account');
    });

    it('resolves a SalesforceModel class string to its table name via reflection', function () {
        Forrest::shouldReceive('describe')
            ->once()
            ->with('Account')
            ->andReturn(minimalDescribeResponse());

        $result = $this->adapter->describe(Account::class);

        expect($result['name'])->toBe('Account');
    });

    it('throws SalesforceException when a non-SalesforceModel object instance is passed', function () {
        expect(fn () => $this->adapter->describe(new stdClass))
            ->toThrow(SalesforceException::class, 'Object must be an instance of SalesforceModel');
    });

    it('throws SalesforceException when a namespaced non-SalesforceModel class string is passed', function () {
        expect(fn () => $this->adapter->describe(Mock::class))
            ->toThrow(SalesforceException::class, 'Class must extend SalesforceModel');
    });

    it('treats a bare unqualified name as a Salesforce object name even when a global alias exists', function () {
        // Regression: Laravel registers global facade aliases like `Event` that make
        // `class_exists('Event')` return true. The adapter must not mistake the Salesforce
        // object name `Event` for a PHP class and reject it as "not a SalesforceModel".
        Forrest::shouldReceive('describe')
            ->once()
            ->with('Event')
            ->andReturn(['name' => 'Event', 'fields' => []]);

        $result = $this->adapter->describe('Event');

        expect($result['name'])->toBe('Event');
    });
});

describe('getTableNameFromClass (via describe())', function () {
    it('falls back to class_basename when the model has no explicit $table property', function () {
        // Account has no explicit $table, so class_basename('...Account') === 'Account'
        Forrest::shouldReceive('describe')
            ->once()
            ->with('Account')
            ->andReturn(minimalDescribeResponse());

        $this->adapter->describe(Account::class);

        // No exception means the correct name ('Account') was resolved and accepted by Forrest
    });

    it('falls back to class_basename for Contact which also has no explicit $table', function () {
        Forrest::shouldReceive('describe')
            ->once()
            ->with('Contact')
            ->andReturn(['name' => 'Contact', 'fields' => []]);

        $result = $this->adapter->describe(Contact::class);

        expect($result['name'])->toBe('Contact');
    });

    it('uses the explicit $table property value when one is defined on the model', function () {
        Forrest::shouldReceive('describe')
            ->once()
            ->with('Account__c')
            ->andReturn(['name' => 'Account__c', 'fields' => []]);

        $result = $this->adapter->describe(AccountWithExplicitTable::class);

        expect($result['name'])->toBe('Account__c');
    });
});

describe('picklistValues()', function () {
    it('returns active picklist values with value, label, and defaultValue keys', function () {
        Forrest::shouldReceive('describe')
            ->with('Account')
            ->andReturn(picklistDescribeResponse());

        $values = $this->adapter->picklistValues('Account', 'Industry');

        expect($values)->toBeArray();
        expect($values)->toHaveCount(2);
        expect($values[0])->toHaveKeys(['value', 'label', 'defaultValue']);
        expect($values[0]['value'])->toBe('Technology');
        expect($values[1]['value'])->toBe('Finance');
    });

    it('filters out inactive picklist values', function () {
        Forrest::shouldReceive('describe')
            ->with('Account')
            ->andReturn(picklistDescribeResponse());

        $values = $this->adapter->picklistValues('Account', 'Industry');

        $returnedValues = array_column($values, 'value');
        expect($returnedValues)->not->toContain('Retired');
    });

    it('preserves the defaultValue flag from the Salesforce metadata', function () {
        Forrest::shouldReceive('describe')
            ->with('Account')
            ->andReturn(picklistDescribeResponse());

        $values = $this->adapter->picklistValues('Account', 'Industry');

        // 'Finance' is set as defaultValue => true in the fixture
        $finance = collect($values)->firstWhere('value', 'Finance');
        expect($finance['defaultValue'])->toBeTrue();

        $technology = collect($values)->firstWhere('value', 'Technology');
        expect($technology['defaultValue'])->toBeFalse();
    });

    it('accepts a multipicklist field type', function () {
        Forrest::shouldReceive('describe')
            ->with('Account')
            ->andReturn(picklistDescribeResponse());

        $values = $this->adapter->picklistValues('Account', 'Channels__c');

        expect($values)->toHaveCount(2);
        expect($values[0]['value'])->toBe('Email');
        expect($values[1]['value'])->toBe('Phone');
    });

    it('throws SalesforceException when the field does not exist on the object', function () {
        Forrest::shouldReceive('describe')
            ->with('Account')
            ->andReturn(picklistDescribeResponse());

        expect(fn () => $this->adapter->picklistValues('Account', 'NonExistent__c'))
            ->toThrow(SalesforceException::class, "Field 'NonExistent__c' not found");
    });

    it('throws SalesforceException when the field is not a picklist type', function () {
        Forrest::shouldReceive('describe')
            ->with('Account')
            ->andReturn(picklistDescribeResponse());

        expect(fn () => $this->adapter->picklistValues('Account', 'Name'))
            ->toThrow(SalesforceException::class, 'not a picklist field');
    });

    it('resolves a SalesforceModel instance through to picklistValues', function () {
        Forrest::shouldReceive('describe')
            ->with('Account')
            ->andReturn(picklistDescribeResponse());

        $values = $this->adapter->picklistValues(new Account, 'Industry');

        expect($values)->toHaveCount(2);
    });

    it('resolves a SalesforceModel class string through to picklistValues', function () {
        Forrest::shouldReceive('describe')
            ->with('Account')
            ->andReturn(picklistDescribeResponse());

        $values = $this->adapter->picklistValues(Account::class, 'Industry');

        expect($values)->toHaveCount(2);
    });

    it('returns an empty array when all picklist values are inactive', function () {
        $response = [
            'name'   => 'Account',
            'fields' => [
                [
                    'name'           => 'Status__c',
                    'type'           => 'picklist',
                    'picklistValues' => [
                        ['value' => 'OldValue', 'label' => 'Old Value', 'active' => false, 'defaultValue' => false],
                    ],
                ],
            ],
        ];

        Forrest::shouldReceive('describe')
            ->with('Account')
            ->andReturn($response);

        $values = $this->adapter->picklistValues('Account', 'Status__c');

        expect($values)->toBeArray();
        expect($values)->toBeEmpty();
    });

    it('returns an empty array when the picklistValues key is absent from field metadata', function () {
        $response = [
            'name'   => 'Account',
            'fields' => [
                [
                    'name' => 'EmptyPicklist__c',
                    'type' => 'picklist',
                    // no 'picklistValues' key
                ],
            ],
        ];

        Forrest::shouldReceive('describe')
            ->with('Account')
            ->andReturn($response);

        $values = $this->adapter->picklistValues('Account', 'EmptyPicklist__c');

        expect($values)->toBeArray();
        expect($values)->toBeEmpty();
    });
});
