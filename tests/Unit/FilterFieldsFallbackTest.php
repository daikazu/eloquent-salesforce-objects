<?php

use Daikazu\EloquentSalesforceObjects\Contracts\AdapterInterface;
use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Illuminate\Support\Facades\Log;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

beforeEach(function () {
    $forrestMock = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
    $this->app->instance('forrest', $forrestMock);
    Forrest::swap($forrestMock);

    // Disable logging channel so fallback logs go through the default facade root,
    // and suppress exceptions so save() returns normally after the fallback.
    config(['eloquent-salesforce-objects.logging_channel' => null]);
    config(['eloquent-salesforce-objects.throw_exceptions' => false]);
});

afterEach(function () {
    Mockery::close();
});

// ---------------------------------------------------------------------------
// filterUpdateableFields fallback
// ---------------------------------------------------------------------------

describe('filterUpdateableFields fallback when describe throws', function () {

    it('strips all update system fields but passes through custom fields when getUpdateableFields throws', function () {
        // Expect one warning log for the failed describe call.
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'Failed to get updateable fields for filtering'));

        $capturedPayload = null;

        $mockAdapter = Mockery::mock(AdapterInterface::class);

        // Metadata call throws — this is the path under test.
        $mockAdapter->shouldReceive('getUpdateableFields')
            ->once()
            ->with('Account')
            ->andThrow(new RuntimeException('API unavailable'));

        // The actual update call should still fire and receive the fallback-filtered payload.
        $mockAdapter->shouldReceive('update')
            ->once()
            ->with(
                'Account',
                '001xx000003DGb2AAG',
                Mockery::on(function (array $data) use (&$capturedPayload) {
                    $capturedPayload = $data;

                    return true;
                })
            )
            ->andReturn(true);

        $this->app->instance(AdapterInterface::class, $mockAdapter);

        $account = new Account([
            'Id'             => '001xx000003DGb2AAG',
            'Name'           => 'Original Name',
            'Industry'       => 'Technology',
            // System fields present in the original record
            'CreatedDate'    => '2024-01-01T00:00:00.000+0000',
            'CreatedById'    => '005xx000001SvulAAC',
            'LastModifiedDate'   => '2024-06-01T00:00:00.000+0000',
            'LastModifiedById'   => '005xx000001SvulAAC',
            'SystemModstamp' => '2024-06-01T00:00:00.000+0000',
            'IsDeleted'      => false,
        ]);
        // Constructor sets exists=true because Id was provided; sync so everything
        // is clean before we dirty the field we want to update.
        $account->syncOriginal();

        // Dirty a normal field AND a system field to prove system fields are stripped
        // even in the fallback path.
        $account->Name = 'Updated Name';
        $account->CreatedDate = '2025-01-01T00:00:00.000+0000';
        $account->SystemModstamp = '2025-01-01T00:00:00.000+0000';

        $account->save();

        // The custom field survives the fallback.
        expect($capturedPayload)->toHaveKey('Name', 'Updated Name');

        // All six update system fields must be stripped even without a successful describe.
        expect($capturedPayload)->not->toHaveKey('CreatedDate');
        expect($capturedPayload)->not->toHaveKey('CreatedById');
        expect($capturedPayload)->not->toHaveKey('LastModifiedDate');
        expect($capturedPayload)->not->toHaveKey('LastModifiedById');
        expect($capturedPayload)->not->toHaveKey('SystemModstamp');
        expect($capturedPayload)->not->toHaveKey('IsDeleted');
    });

    it('passes through multiple custom fields in the fallback payload when getUpdateableFields throws', function () {
        Log::shouldReceive('warning')->once();

        $capturedPayload = null;

        $mockAdapter = Mockery::mock(AdapterInterface::class);
        $mockAdapter->shouldReceive('getUpdateableFields')
            ->once()
            ->andThrow(new RuntimeException('Metadata cache miss'));

        $mockAdapter->shouldReceive('update')
            ->once()
            ->with('Account', '001xx000003DGb2AAG', Mockery::on(function (array $data) use (&$capturedPayload) {
                $capturedPayload = $data;

                return true;
            }))
            ->andReturn(true);

        $this->app->instance(AdapterInterface::class, $mockAdapter);

        $account = new Account([
            'Id'       => '001xx000003DGb2AAG',
            'Name'     => 'Original',
            'Industry' => 'Technology',
            'Type'     => 'Customer',
        ]);
        $account->syncOriginal();

        $account->Name = 'Updated';
        $account->Industry = 'Finance';
        $account->Type = 'Partner';

        $account->save();

        expect($capturedPayload)->toHaveKey('Name', 'Updated');
        expect($capturedPayload)->toHaveKey('Industry', 'Finance');
        expect($capturedPayload)->toHaveKey('Type', 'Partner');
    });

    it('returns successfully and marks model as not dirty after the fallback update', function () {
        Log::shouldReceive('warning')->once();

        $mockAdapter = Mockery::mock(AdapterInterface::class);
        $mockAdapter->shouldReceive('getUpdateableFields')->andThrow(new RuntimeException('Timeout'));
        $mockAdapter->shouldReceive('update')->once()->andReturn(true);

        $this->app->instance(AdapterInterface::class, $mockAdapter);

        $account = new Account([
            'Id'   => '001xx000003DGb2AAG',
            'Name' => 'Original',
        ]);
        $account->syncOriginal();
        $account->Name = 'Updated';

        $result = $account->save();

        expect($result)->toBeTrue();
        expect($account->isDirty())->toBeFalse();
    });

    it('uses only updateable fields from a successful describe call (normal path)', function () {
        $describeResponse = [
            'fields' => [
                ['name' => 'Id', 'createable' => false, 'updateable' => false],
                ['name' => 'Name', 'createable' => true, 'updateable' => true],
                ['name' => 'Industry', 'createable' => true, 'updateable' => true],
                // Create-only field — must be stripped on update
                ['name' => 'RecordTypeId', 'createable' => true, 'updateable' => false],
                ['name' => 'CreatedDate', 'createable' => false, 'updateable' => false],
                ['name' => 'SystemModstamp', 'createable' => false, 'updateable' => false],
            ],
        ];

        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->once()->andReturn($describeResponse);

        $capturedPayload = null;

        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Account/001xx000003DGb2AAG', Mockery::on(function (array $data) use (&$capturedPayload) {
                $capturedPayload = $data['body'] ?? null;

                return $data['method'] === 'patch';
            }))
            ->andReturn([]);

        $account = new Account([
            'Id'           => '001xx000003DGb2AAG',
            'Name'         => 'Original',
            'Industry'     => 'Technology',
            'RecordTypeId' => '012000000000001',
        ]);
        $account->syncOriginal();

        $account->Name = 'Updated';
        $account->Industry = 'Finance';
        $account->RecordTypeId = '012000000000002'; // create-only, must be stripped

        $account->save();

        expect($capturedPayload)->toHaveKey('Name', 'Updated');
        expect($capturedPayload)->toHaveKey('Industry', 'Finance');
        expect($capturedPayload)->not->toHaveKey('RecordTypeId');
    });
});

// ---------------------------------------------------------------------------
// filterCreateableFields fallback
// ---------------------------------------------------------------------------

describe('filterCreateableFields fallback when describe throws', function () {

    it('strips insert system fields but passes through custom fields when getCreateableFields throws', function () {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'Failed to get createable fields for filtering'));

        $capturedPayload = null;

        $mockAdapter = Mockery::mock(AdapterInterface::class);

        $mockAdapter->shouldReceive('getCreateableFields')
            ->once()
            ->with('Account')
            ->andThrow(new RuntimeException('API unavailable'));

        $mockAdapter->shouldReceive('create')
            ->once()
            ->with(
                'Account',
                Mockery::on(function (array $data) use (&$capturedPayload) {
                    $capturedPayload = $data;

                    return true;
                })
            )
            ->andReturn(['id' => '001xx000003DGb3BBB', 'success' => true]);

        $this->app->instance(AdapterInterface::class, $mockAdapter);

        $account = new Account([
            'Name'             => 'New Company',
            'Industry'         => 'Technology',
            // System fields that the caller erroneously passes — must be stripped.
            'CreatedDate'      => '2024-01-01T00:00:00.000+0000',
            'LastModifiedDate' => '2024-01-01T00:00:00.000+0000',
            'LastModifiedById' => '005xx000001SvulAAC',
            'SystemModstamp'   => '2024-01-01T00:00:00.000+0000',
            'IsDeleted'        => false,
        ]);

        $account->save();

        // Custom fields survive the fallback.
        expect($capturedPayload)->toHaveKey('Name', 'New Company');
        expect($capturedPayload)->toHaveKey('Industry', 'Technology');

        // The five insert system fields must be stripped in the fallback path.
        expect($capturedPayload)->not->toHaveKey('CreatedDate');
        expect($capturedPayload)->not->toHaveKey('LastModifiedDate');
        expect($capturedPayload)->not->toHaveKey('LastModifiedById');
        expect($capturedPayload)->not->toHaveKey('SystemModstamp');
        expect($capturedPayload)->not->toHaveKey('IsDeleted');
    });

    it('CreatedById is NOT stripped on insert fallback because it is not in the insert system fields list', function () {
        // Note: filterCreateableFields does NOT strip CreatedById (unlike filterUpdateableFields).
        // This test documents the intentional asymmetry between the two lists.
        Log::shouldReceive('warning')->once();

        $capturedPayload = null;

        $mockAdapter = Mockery::mock(AdapterInterface::class);
        $mockAdapter->shouldReceive('getCreateableFields')
            ->once()
            ->andThrow(new RuntimeException('Timeout'));

        $mockAdapter->shouldReceive('create')
            ->once()
            ->with('Account', Mockery::on(function (array $data) use (&$capturedPayload) {
                $capturedPayload = $data;

                return true;
            }))
            ->andReturn(['id' => '001xx000003DGb4CCC', 'success' => true]);

        $this->app->instance(AdapterInterface::class, $mockAdapter);

        $account = new Account([
            'Name'        => 'New Company',
            'CreatedById' => '005xx000001SvulAAC', // present in update list but NOT insert list
        ]);

        $account->save();

        // CreatedById passes through on insert fallback (not in insert system fields list).
        expect($capturedPayload)->toHaveKey('CreatedById', '005xx000001SvulAAC');
    });

    it('saves returned Salesforce ID onto the model after fallback insert', function () {
        Log::shouldReceive('warning')->once();

        $mockAdapter = Mockery::mock(AdapterInterface::class);
        $mockAdapter->shouldReceive('getCreateableFields')->andThrow(new RuntimeException('Timeout'));
        $mockAdapter->shouldReceive('create')
            ->once()
            ->andReturn(['id' => '001xx000003DGb5DDD', 'success' => true]);

        $this->app->instance(AdapterInterface::class, $mockAdapter);

        $account = new Account(['Name' => 'New Company']);
        $account->save();

        expect($account->exists)->toBeTrue();
        expect($account->wasRecentlyCreated)->toBeTrue();
        expect($account->Id)->toBe('001xx000003DGb5DDD');
    });
});

// ---------------------------------------------------------------------------
// Short-circuit: empty attributes
// ---------------------------------------------------------------------------

describe('empty-attributes short-circuit', function () {

    it('returns empty array immediately without touching the adapter when update attributes are empty', function () {
        // No adapter calls should be made at all — the model is clean after syncOriginal().
        $mockAdapter = Mockery::mock(AdapterInterface::class);
        $mockAdapter->shouldNotReceive('getUpdateableFields');
        $mockAdapter->shouldNotReceive('update');

        $this->app->instance(AdapterInterface::class, $mockAdapter);

        $account = new Account([
            'Id'   => '001xx000003DGb2AAG',
            'Name' => 'No Changes',
        ]);
        $account->syncOriginal();

        // Nothing is dirty → getDirtyForUpdate returns [] → performUpdate returns true early.
        $result = $account->save();

        expect($result)->toBeTrue();
    });

    it('returns empty array immediately without touching the adapter when insert attributes are empty after key removal', function () {
        // An account with only an Id set (no other attributes) should produce an empty
        // create payload. The short-circuit fires before getCreateableFields is called,
        // but the adapter->create will still be called with the empty array.
        // The important assertion is that getCreateableFields is never called.
        $mockAdapter = Mockery::mock(AdapterInterface::class);
        $mockAdapter->shouldNotReceive('getCreateableFields');
        $mockAdapter->shouldReceive('create')
            ->once()
            ->andReturn(['id' => '001xx000003DGb6EEE', 'success' => true]);

        $this->app->instance(AdapterInterface::class, $mockAdapter);

        // Build a brand-new account whose only attribute after stripping 'Id' and
        // 'attributes' metadata is empty — triggering the short-circuit in filterCreateableFields.
        $account = new Account([]);
        // Force it to be a new record (not existing) so performInsert fires.
        $account->exists = false;

        $result = $account->save();

        expect($result)->toBeTrue();
    });
});
