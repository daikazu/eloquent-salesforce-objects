<?php

use Carbon\Carbon;
use Daikazu\EloquentSalesforceObjects\Examples\Account;
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

describe('timestamp casting', function () {
    it('casts CreatedDate to Carbon instance', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'          => '001xx000003DGb2AAG',
                        'Name'        => 'Test Account',
                        'CreatedDate' => '2024-01-15T10:30:00.000+0000',
                        'attributes'  => ['type' => 'Account'],
                    ],
                ],
            ]);

        $account = Account::first();

        expect($account->CreatedDate)->toBeInstanceOf(Carbon::class);
        expect($account->CreatedDate->year)->toBe(2024);
        expect($account->CreatedDate->month)->toBe(1);
        expect($account->CreatedDate->day)->toBe(15);
    });

    it('casts LastModifiedDate to Carbon instance', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'               => '001xx000003DGb2AAG',
                        'Name'             => 'Test Account',
                        'LastModifiedDate' => '2024-02-20T14:45:30.000+0000',
                        'attributes'       => ['type' => 'Account'],
                    ],
                ],
            ]);

        $account = Account::first();

        expect($account->LastModifiedDate)->toBeInstanceOf(Carbon::class);
        expect($account->LastModifiedDate->year)->toBe(2024);
        expect($account->LastModifiedDate->month)->toBe(2);
        expect($account->LastModifiedDate->day)->toBe(20);
    });

    it('allows using diffForHumans on timestamps', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Set a known time for testing
        $now = Carbon::parse('2024-03-01 12:00:00');
        Carbon::setTestNow($now);

        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'          => '001xx000003DGb2AAG',
                        'Name'        => 'Test Account',
                        'CreatedDate' => '2024-02-28T12:00:00.000+0000', // 2 days ago
                        'attributes'  => ['type' => 'Account'],
                    ],
                ],
            ]);

        $account = Account::first();

        expect($account->CreatedDate->diffForHumans())->toContain('2 days ago');

        Carbon::setTestNow(); // Reset
    });

    it('allows using format method on timestamps', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'          => '001xx000003DGb2AAG',
                        'Name'        => 'Test Account',
                        'CreatedDate' => '2024-01-15T10:30:00.000+0000',
                        'attributes'  => ['type' => 'Account'],
                    ],
                ],
            ]);

        $account = Account::first();

        expect($account->CreatedDate->format('Y-m-d'))->toBe('2024-01-15');
        expect($account->CreatedDate->format('F j, Y'))->toBe('January 15, 2024');
    });

    it('can convert timestamps to different timezones', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'          => '001xx000003DGb2AAG',
                        'Name'        => 'Test Account',
                        'CreatedDate' => '2024-01-15T15:00:00.000+0000', // 3 PM UTC
                        'attributes'  => ['type' => 'Account'],
                    ],
                ],
            ]);

        $account = Account::first();

        // Timestamps are stored in UTC by Salesforce
        expect($account->CreatedDate->hour)->toBe(15); // 3 PM UTC

        // You can convert to any timezone using Carbon methods
        $newYorkTime = $account->CreatedDate->setTimezone('America/New_York');
        expect($newYorkTime->hour)->toBe(10); // 10 AM EST (UTC-5)

        // Or use the tz() helper
        $tokyoTime = $account->CreatedDate->tz('Asia/Tokyo');
        expect($tokyoTime->hour)->toBe(0); // Midnight (UTC+9)
    });

    it('allows chaining Carbon methods', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        $now = Carbon::parse('2024-03-15 12:00:00');
        Carbon::setTestNow($now);

        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'          => '001xx000003DGb2AAG',
                        'Name'        => 'Test Account',
                        'CreatedDate' => '2024-03-01T08:00:00.000+0000',
                        'attributes'  => ['type' => 'Account'],
                    ],
                ],
            ]);

        $account = Account::first();

        // Chain multiple Carbon methods
        $result = $account->CreatedDate
            ->addDays(5)
            ->startOfDay()
            ->format('Y-m-d H:i:s');

        expect($result)->toBe('2024-03-06 00:00:00');

        Carbon::setTestNow(); // Reset
    });

    it('handles null timestamps gracefully', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'          => '001xx000003DGb2AAG',
                        'Name'        => 'Test Account',
                        'CreatedDate' => null,
                        'attributes'  => ['type' => 'Account'],
                    ],
                ],
            ]);

        $account = Account::first();

        expect($account->CreatedDate)->toBeNull();
    });

    it('allows comparison between timestamps', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'               => '001xx000003DGb2AAG',
                        'Name'             => 'Test Account',
                        'CreatedDate'      => '2024-01-15T10:00:00.000+0000',
                        'LastModifiedDate' => '2024-02-20T14:00:00.000+0000',
                        'attributes'       => ['type' => 'Account'],
                    ],
                ],
            ]);

        $account = Account::first();

        expect($account->LastModifiedDate->isAfter($account->CreatedDate))->toBeTrue();
        expect($account->CreatedDate->isBefore($account->LastModifiedDate))->toBeTrue();

        // Calculate the difference (should be approximately 36 days)
        $daysDiff = abs($account->CreatedDate->diffInDays($account->LastModifiedDate));
        expect($daysDiff)->toBeGreaterThan(30);
    });

    it('works with newly created models', function () {
        // When creating a new model, the timestamps should be set by Salesforce
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Account', Mockery::any())
            ->andReturn([
                'id'      => '001xx000003DGb2AAG',
                'success' => true,
            ]);

        // Mock the refresh query that fetches the record after creation
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'          => '001xx000003DGb2AAG',
                        'Name'        => 'New Account',
                        'CreatedDate' => '2024-03-15T10:30:00.000+0000',
                        'attributes'  => ['type' => 'Account'],
                    ],
                ],
            ]);

        $account = Account::create(['Name' => 'New Account']);

        // Reload to get the timestamps
        $account->refresh();

        expect($account->CreatedDate)->toBeInstanceOf(Carbon::class);
    });

    it('preserves timezone when converting to array', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'          => '001xx000003DGb2AAG',
                        'Name'        => 'Test Account',
                        'CreatedDate' => '2024-01-15T10:30:00.000+0000',
                        'attributes'  => ['type' => 'Account'],
                    ],
                ],
            ]);

        $account = Account::first();

        $array = $account->toArray();

        expect($array['CreatedDate'])->toBeString();
        expect($array['CreatedDate'])->toContain('2024-01-15');
    });

    it('serializes dates in Salesforce-compatible format', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'          => '001xx000003DGb2AAG',
                        'Name'        => 'Test Account',
                        'CreatedDate' => '2024-01-15T10:30:00.000+0000',
                        'attributes'  => ['type' => 'Account'],
                    ],
                ],
            ]);

        $account = Account::first();

        // Get the date format that will be sent to Salesforce
        $dateFormat = $account->getDateFormat();

        // Format a test date
        $testDate = Carbon::parse('2024-01-15 10:30:00');
        $formatted = $testDate->format($dateFormat);

        // Verify it matches Salesforce's expected format
        // Format should be: Y-m-d\TH:i:s.vO (e.g., 2024-01-15T10:30:00.000+0000)
        expect($formatted)->toContain('T'); // Has T separator
        expect($formatted)->toMatch('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/'); // Has proper datetime format
        expect($formatted)->toMatch('/[+-]\d{4}$/'); // Has timezone offset
    });

    it('uses correct date format constant', function () {
        $account = new Account;

        // Verify the date format constant is correctly defined
        expect(Account::DATED_FORMAT)->toBe('Y-m-d\TH:i:s.vO');
        expect($account->getDateFormat())->toBe('Y-m-d\TH:i:s.vO');
    });

    it('formats dates with milliseconds and timezone for Salesforce', function () {
        $testDate = Carbon::parse('2024-01-15 10:30:45', 'UTC');

        // Format using Salesforce format
        $formatted = $testDate->format('Y-m-d\TH:i:s.vO');

        // Should produce something like: 2024-01-15T10:30:45.000+0000
        expect($formatted)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{4}$/');
        expect($formatted)->toStartWith('2024-01-15T10:30:45');
    });

    it('works with models that define custom casts', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock describe for the Opportunity object
        Forrest::shouldReceive('describe')
            ->andReturn([
                'fields' => [
                    ['name' => 'Id', 'type' => 'id'],
                    ['name' => 'Name', 'type' => 'string'],
                    ['name' => 'CreatedDate', 'type' => 'datetime'],
                    ['name' => 'CloseDate', 'type' => 'date'],
                ],
            ]);

        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'          => '006xx000003DGb2AAG',
                        'Name'        => 'Test Opportunity',
                        'CreatedDate' => '2021-11-22T16:47:33.000+0000',
                        'CloseDate'   => '2024-12-31',
                        'attributes'  => ['type' => 'Opportunity'],
                    ],
                ],
            ]);

        $opportunity = \Daikazu\EloquentSalesforceObjects\Examples\Opportunity::first();

        // CreatedDate should be a Carbon instance (from parent casts)
        expect($opportunity->CreatedDate)->toBeInstanceOf(Carbon::class);
        expect($opportunity->CreatedDate->year)->toBe(2021);
        expect($opportunity->CreatedDate->month)->toBe(11);
        expect($opportunity->CreatedDate->day)->toBe(22);

        // CloseDate should also be a Carbon instance (from custom casts)
        expect($opportunity->CloseDate)->toBeInstanceOf(Carbon::class);
        expect($opportunity->CloseDate->year)->toBe(2024);
    });
});
