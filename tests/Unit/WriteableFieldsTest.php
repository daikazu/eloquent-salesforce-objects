<?php

use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

beforeEach(function () {
    $forrestMock = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
    $this->app->instance('forrest', $forrestMock);
    Forrest::swap($forrestMock);

    // Fake describe response with mixed field permissions
    $this->describeResponse = [
        'fields' => [
            ['name' => 'Id', 'createable' => false, 'updateable' => false],
            ['name' => 'Name', 'createable' => true, 'updateable' => true],
            ['name' => 'Industry', 'createable' => true, 'updateable' => true],
            ['name' => 'OwnerId', 'createable' => true, 'updateable' => true],
            // Create-only field — the bug scenario from issue #7
            ['name' => 'RecordTypeId', 'createable' => true, 'updateable' => false],
            // Another create-only field
            ['name' => 'My_Column__c', 'createable' => true, 'updateable' => false],
            // System/read-only fields
            ['name' => 'CreatedDate', 'createable' => false, 'updateable' => false],
            ['name' => 'CreatedById', 'createable' => false, 'updateable' => false],
            ['name' => 'LastModifiedDate', 'createable' => false, 'updateable' => false],
            ['name' => 'LastModifiedById', 'createable' => false, 'updateable' => false],
            ['name' => 'SystemModstamp', 'createable' => false, 'updateable' => false],
            ['name' => 'IsDeleted', 'createable' => false, 'updateable' => false],
            // Formula field (read-only)
            ['name' => 'Formula__c', 'createable' => false, 'updateable' => false],
        ],
    ];
});

afterEach(function () {
    Mockery::close();
});

describe('SalesforceAdapter writeable field methods', function () {
    it('getWriteableFields returns both createable and updateable arrays', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->once()->andReturn($this->describeResponse);

        $adapter = app(\Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter::class);
        $fields = $adapter->getWriteableFields('Account');

        expect($fields)->toHaveKeys(['createable', 'updateable']);
        expect($fields['createable'])->toContain('Name', 'Industry', 'RecordTypeId', 'My_Column__c');
        expect($fields['createable'])->not->toContain('CreatedDate', 'SystemModstamp', 'Formula__c');
        expect($fields['updateable'])->toContain('Name', 'Industry', 'OwnerId');
        expect($fields['updateable'])->not->toContain('RecordTypeId', 'My_Column__c', 'CreatedDate');
    });

    it('getCreateableFields returns only createable fields', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->once()->andReturn($this->describeResponse);

        $adapter = app(\Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter::class);
        $fields = $adapter->getCreateableFields('Account');

        expect($fields)->toContain('Name', 'Industry', 'RecordTypeId', 'My_Column__c');
        expect($fields)->not->toContain('CreatedDate', 'SystemModstamp', 'Id', 'Formula__c');
    });

    it('getUpdateableFields returns only updateable fields', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->once()->andReturn($this->describeResponse);

        $adapter = app(\Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter::class);
        $fields = $adapter->getUpdateableFields('Account');

        expect($fields)->toContain('Name', 'Industry', 'OwnerId');
        expect($fields)->not->toContain('RecordTypeId', 'My_Column__c', 'CreatedDate');
    });

    it('getAllWriteableFields returns flat unique union', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->once()->andReturn($this->describeResponse);

        $adapter = app(\Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter::class);
        $fields = $adapter->getAllWriteableFields('Account');

        expect($fields)->toContain('Name', 'Industry', 'OwnerId', 'RecordTypeId', 'My_Column__c');
        expect($fields)->not->toContain('CreatedDate', 'SystemModstamp', 'Id', 'Formula__c');
        // Verify no duplicates
        expect($fields)->toHaveCount(count(array_unique($fields)));
    });
});

describe('insert operations use createable fields', function () {
    it('includes create-only fields when inserting', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->andReturn($this->describeResponse);

        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Account', Mockery::on(function ($data) {
                // Create-only fields MUST be present
                return $data['method'] === 'post'
                    && $data['body']['Name'] === 'Test Company'
                    && $data['body']['RecordTypeId'] === '012000000000001'
                    && $data['body']['My_Column__c'] === 'initial value';
            }))
            ->andReturn(['id' => '001xx000003DGb2AAG', 'success' => true]);

        $account = Account::create([
            'Name'         => 'Test Company',
            'RecordTypeId' => '012000000000001',
            'My_Column__c' => 'initial value',
        ]);

        expect($account->exists)->toBeTrue();
        expect($account->Id)->toBe('001xx000003DGb2AAG');
    });

    it('strips read-only fields when inserting', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->andReturn($this->describeResponse);

        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Account', Mockery::on(function ($data) {
                // Read-only and system fields must NOT be present
                return $data['method'] === 'post'
                    && isset($data['body']['Name'])
                    && ! isset($data['body']['CreatedDate'])
                    && ! isset($data['body']['SystemModstamp'])
                    && ! isset($data['body']['Formula__c']);
            }))
            ->andReturn(['id' => '001xx000003DGb2AAG', 'success' => true]);

        $account = new Account([
            'Name'           => 'Test Company',
            'CreatedDate'    => '2025-01-01T00:00:00.000+0000',
            'SystemModstamp' => '2025-01-01T00:00:00.000+0000',
            'Formula__c'     => 'should be stripped',
        ]);

        $account->save();

        expect($account->exists)->toBeTrue();
    });
});

describe('update operations use updateable fields', function () {
    it('strips create-only fields when updating', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->andReturn($this->describeResponse);

        $account = new Account([
            'Id'            => '001xx000003DGb2AAG',
            'Name'          => 'Original Name',
            'RecordTypeId'  => '012000000000001',
            'My_Column__c'  => 'original',
        ]);
        $account->exists = true;
        $account->syncOriginal();

        // Dirty both a normal field and a create-only field
        $account->Name = 'Updated Name';
        $account->RecordTypeId = '012000000000002';
        $account->My_Column__c = 'changed';

        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Account/001xx000003DGb2AAG', Mockery::on(function ($data) {
                // Updateable field should be present
                // Create-only fields should NOT be present
                return $data['method'] === 'patch'
                    && $data['body']['Name'] === 'Updated Name'
                    && ! isset($data['body']['RecordTypeId'])
                    && ! isset($data['body']['My_Column__c']);
            }))
            ->andReturn([]);

        $result = $account->save();

        expect($result)->toBeTrue();
    });

    it('includes updateable fields when updating', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->andReturn($this->describeResponse);

        $account = new Account([
            'Id'       => '001xx000003DGb2AAG',
            'Name'     => 'Original',
            'Industry' => 'Technology',
        ]);
        $account->exists = true;
        $account->syncOriginal();

        $account->Name = 'Updated';
        $account->Industry = 'Finance';

        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Account/001xx000003DGb2AAG', Mockery::on(function ($data) {
                return $data['method'] === 'patch'
                    && $data['body']['Name'] === 'Updated'
                    && $data['body']['Industry'] === 'Finance';
            }))
            ->andReturn([]);

        $result = $account->save();

        expect($result)->toBeTrue();
    });
});
