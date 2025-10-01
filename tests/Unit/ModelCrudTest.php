<?php

use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Daikazu\EloquentSalesforceObjects\Exceptions\SalesforceException;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

beforeEach(function () {
    // Mock the Forrest service in the container
    $forrestMock = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
    $this->app->instance('forrest', $forrestMock);
    Forrest::swap($forrestMock);
});

afterEach(function () {
    Mockery::close();
});

describe('create operations', function () {
    it('creates a new record and returns the ID', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock the create operation
        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Account', Mockery::on(function ($data) {
                return $data['method'] === 'post' &&
                       $data['body']['Name'] === 'Test Company' &&
                       $data['body']['Industry'] === 'Technology' &&
                       ! isset($data['body']['Id']); // Id should not be sent on create
            }))
            ->andReturn([
                'id'      => '001xx000003DGb2AAG',
                'success' => true,
            ]);

        $account = new Account([
            'Name'     => 'Test Company',
            'Industry' => 'Technology',
        ]);

        $result = $account->save();

        expect($result)->toBeTrue();
        expect($account->exists)->toBeTrue();
        expect($account->wasRecentlyCreated)->toBeTrue();
        expect($account->Id)->toBe('001xx000003DGb2AAG');
    });

    it('creates record using static create method', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Account', Mockery::on(function ($data) {
                return $data['method'] === 'post' &&
                       $data['body']['Name'] === 'Test Company';
            }))
            ->andReturn([
                'id'      => '001xx000003DGb2AAG',
                'success' => true,
            ]);

        $account = Account::create([
            'Name' => 'Test Company',
        ]);

        expect($account)->toBeInstanceOf(Account::class);
        expect($account->exists)->toBeTrue();
        expect($account->wasRecentlyCreated)->toBeTrue();
        expect($account->Id)->toBe('001xx000003DGb2AAG');
    });

    it('does not send metadata attribute to Salesforce on create', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Account', Mockery::on(function ($data) {
                // Verify 'attributes' metadata is not sent
                return $data['method'] === 'post' &&
                       ! isset($data['body']['attributes']);
            }))
            ->andReturn([
                'id'      => '001xx000003DGb2AAG',
                'success' => true,
            ]);

        $account = new Account(['Name' => 'Test Company']);
        $account->save();

        expect(true)->toBeTrue();
    });

    it('does not send primary key on create', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Account', Mockery::on(function ($data) {
                // Verify Id is not sent even if manually set
                return $data['method'] === 'post' &&
                       ! isset($data['body']['Id']);
            }))
            ->andReturn([
                'id'      => '001xx000003DGb2AAG',
                'success' => true,
            ]);

        $account = new Account([
            'Name' => 'Test Company',
        ]);
        // Manually set Id after construction (but model is still new)
        $account->exists = false;
        $account->setAttribute('Id', 'should-be-ignored');

        $account->save();

        // Should have the Salesforce-assigned ID, not the one we tried to set
        expect($account->Id)->toBe('001xx000003DGb2AAG');
    });

    it('handles create errors and throws exception when configured', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('sobjects')
            ->once()
            ->andThrow(new Exception('REQUIRED_FIELD_MISSING: Name is required'));

        config(['eloquent-salesforce-objects.throw_exceptions' => true]);

        $account = new Account([]);

        expect(fn () => $account->save())
            ->toThrow(Exception::class, 'REQUIRED_FIELD_MISSING');
    });

    it('returns false on create error when throw_exceptions is false', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('sobjects')
            ->once()
            ->andThrow(new Exception('REQUIRED_FIELD_MISSING'));

        config(['eloquent-salesforce-objects.throw_exceptions' => false]);

        $account = new Account(['Name' => 'Test']);
        $result = $account->save();

        expect($result)->toBeFalse();
        expect($account->exists)->toBeFalse();
    });
});

describe('update operations', function () {
    it('updates an existing record with dirty attributes', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Create an existing account
        $account = new Account([
            'Id'       => '001xx000003DGb2AAG',
            'Name'     => 'Original Name',
            'Industry' => 'Technology',
        ]);
        $account->exists = true;
        $account->syncOriginal(); // Mark as not dirty

        // Now modify it
        $account->Name = 'Updated Name';

        // Mock the update operation
        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Account/001xx000003DGb2AAG', Mockery::on(function ($data) {
                // Should only send the dirty field
                return $data['method'] === 'patch' &&
                       $data['body']['Name'] === 'Updated Name' &&
                       ! isset($data['body']['Industry']) && // Not dirty, shouldn't be sent
                       ! isset($data['body']['Id']); // Never send ID
            }))
            ->andReturn([]);

        $result = $account->save();

        expect($result)->toBeTrue();
        expect($account->exists)->toBeTrue();
        expect($account->wasRecentlyCreated)->toBeFalse();
    });

    it('does not call API when no attributes are dirty', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Create an existing account with no changes
        $account = new Account([
            'Id'   => '001xx000003DGb2AAG',
            'Name' => 'Test Company',
        ]);
        $account->exists = true;
        $account->syncOriginal();

        // Should not make any API call since nothing changed
        Forrest::shouldNotReceive('sobjects');

        $result = $account->save();

        expect($result)->toBeTrue();
    });

    it('does not send metadata or ID on update', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        $account = new Account([
            'Id'   => '001xx000003DGb2AAG',
            'Name' => 'Original Name',
        ]);
        $account->exists = true;
        $account->syncOriginal();

        $account->Name = 'Updated Name';

        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Account/001xx000003DGb2AAG', Mockery::on(function ($data) {
                return $data['method'] === 'patch' &&
                       ! isset($data['body']['Id']) &&
                       ! isset($data['body']['attributes']);
            }))
            ->andReturn([]);

        $account->save();

        expect(true)->toBeTrue();
    });

    it('handles update errors and throws exception when configured', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        $account = new Account([
            'Id'   => '001xx000003DGb2AAG',
            'Name' => 'Test',
        ]);
        $account->exists = true;
        $account->syncOriginal();

        $account->Name = 'Updated';

        Forrest::shouldReceive('sobjects')
            ->once()
            ->andThrow(new Exception('ENTITY_IS_DELETED'));

        config(['eloquent-salesforce-objects.throw_exceptions' => true]);

        expect(fn () => $account->save())
            ->toThrow(Exception::class, 'ENTITY_IS_DELETED');
    });

    it('returns false on update error when throw_exceptions is false', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        $account = new Account([
            'Id'   => '001xx000003DGb2AAG',
            'Name' => 'Test',
        ]);
        $account->exists = true;
        $account->syncOriginal();

        $account->Name = 'Updated';

        Forrest::shouldReceive('sobjects')
            ->once()
            ->andThrow(new Exception('ENTITY_IS_DELETED'));

        config(['eloquent-salesforce-objects.throw_exceptions' => false]);

        $result = $account->save();

        expect($result)->toBeFalse();
    });

    it('updates multiple attributes at once', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        $account = new Account([
            'Id'       => '001xx000003DGb2AAG',
            'Name'     => 'Original Name',
            'Industry' => 'Technology',
            'Type'     => 'Customer',
        ]);
        $account->exists = true;
        $account->syncOriginal();

        // Update multiple fields
        $account->Name = 'Updated Name';
        $account->Industry = 'Finance';

        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Account/001xx000003DGb2AAG', Mockery::on(function ($data) {
                return $data['method'] === 'patch' &&
                       $data['body']['Name'] === 'Updated Name' &&
                       $data['body']['Industry'] === 'Finance' &&
                       ! isset($data['body']['Type']); // Not dirty
            }))
            ->andReturn([]);

        $result = $account->save();

        expect($result)->toBeTrue();
    });
});

describe('delete operations', function () {
    it('deletes an existing record', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        $account = new Account([
            'Id'   => '001xx000003DGb2AAG',
            'Name' => 'Test Company',
        ]);
        $account->exists = true;

        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Account/001xx000003DGb2AAG', ['method' => 'delete'])
            ->andReturn([]);

        $result = $account->delete();

        expect($result)->toBeTrue();
        expect($account->exists)->toBeFalse();
    });

    it('returns false when deleting non-existent model', function () {
        $account = new Account(['Name' => 'Test']);
        $account->exists = false;

        // Should not make any API call
        Forrest::shouldNotReceive('sobjects');

        $result = $account->delete();

        expect($result)->toBeFalse();
    });

    it('handles delete errors and throws exception when configured', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        $account = new Account([
            'Id'   => '001xx000003DGb2AAG',
            'Name' => 'Test',
        ]);
        $account->exists = true;

        Forrest::shouldReceive('sobjects')
            ->once()
            ->andThrow(new Exception('ENTITY_IS_DELETED'));

        config(['eloquent-salesforce-objects.throw_exceptions' => true]);

        expect(fn () => $account->delete())
            ->toThrow(Exception::class);
    });

    it('returns false on delete error when throw_exceptions is false', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        $account = new Account([
            'Id'   => '001xx000003DGb2AAG',
            'Name' => 'Test',
        ]);
        $account->exists = true;

        Forrest::shouldReceive('sobjects')
            ->once()
            ->andThrow(new Exception('ENTITY_IS_DELETED'));

        config(['eloquent-salesforce-objects.throw_exceptions' => false]);

        $result = $account->delete();

        expect($result)->toBeFalse();
    });

    it('forceDelete delegates to delete', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        $account = new Account([
            'Id'   => '001xx000003DGb2AAG',
            'Name' => 'Test',
        ]);
        $account->exists = true;

        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Account/001xx000003DGb2AAG', ['method' => 'delete'])
            ->andReturn([]);

        $result = $account->forceDelete();

        expect($result)->toBeTrue();
        expect($account->exists)->toBeFalse();
    });

    it('restore throws exception', function () {
        $account = new Account([
            'Id'   => '001xx000003DGb2AAG',
            'Name' => 'Test',
        ]);

        expect(fn () => $account->restore())
            ->toThrow(SalesforceException::class, 'does not natively support UNDELETE');
    });
});

describe('full CRUD workflow', function () {
    it('completes full create-update-delete cycle', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // 1. CREATE
        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Account', Mockery::on(function ($data) {
                return $data['method'] === 'post';
            }))
            ->andReturn([
                'id'      => '001xx000003DGb2AAG',
                'success' => true,
            ]);

        $account = Account::create([
            'Name'     => 'Test Company',
            'Industry' => 'Technology',
        ]);

        expect($account->Id)->toBe('001xx000003DGb2AAG');
        expect($account->exists)->toBeTrue();
        expect($account->wasRecentlyCreated)->toBeTrue();

        // 2. UPDATE
        $account->syncOriginal(); // Mark as clean
        $account->wasRecentlyCreated = false;
        $account->Industry = 'Finance';

        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Account/001xx000003DGb2AAG', Mockery::on(function ($data) {
                return $data['method'] === 'patch' &&
                       $data['body']['Industry'] === 'Finance';
            }))
            ->andReturn([]);

        $result = $account->save();
        expect($result)->toBeTrue();

        // 3. DELETE
        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Account/001xx000003DGb2AAG', ['method' => 'delete'])
            ->andReturn([]);

        $result = $account->delete();
        expect($result)->toBeTrue();
        expect($account->exists)->toBeFalse();
    });

    it('handles model state correctly through lifecycle', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // New model
        $account = new Account(['Name' => 'Test']);
        expect($account->exists)->toBeFalse();
        expect($account->wasRecentlyCreated)->toBeFalse();

        // After create
        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Account', Mockery::on(function ($data) {
                return $data['method'] === 'post';
            }))
            ->andReturn(['id' => '001xx000001', 'success' => true]);

        $account->save();
        expect($account->exists)->toBeTrue();
        expect($account->wasRecentlyCreated)->toBeTrue();

        // After subsequent save (update)
        $account->syncOriginal();
        $account->wasRecentlyCreated = false;
        $account->Name = 'Updated';

        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Account/001xx000001', Mockery::on(function ($data) {
                return $data['method'] === 'patch';
            }))
            ->andReturn([]);

        $account->save();
        expect($account->exists)->toBeTrue();
        expect($account->wasRecentlyCreated)->toBeFalse();

        // After delete
        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Account/001xx000001', ['method' => 'delete'])
            ->andReturn([]);

        $account->delete();
        expect($account->exists)->toBeFalse();
    });
});
