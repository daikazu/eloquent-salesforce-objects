<?php

/**
 * Salesforce has no atomic increment. A model's increment()/decrement() computes the
 * new value and saves it; query-level increment() throws.
 */

use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

beforeEach(function () {
    $forrestMock = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
    $this->app->instance('forrest', $forrestMock);
    Forrest::swap($forrestMock);
    Forrest::shouldReceive('hasToken')->andReturn(true);
    config(['eloquent-salesforce-objects.throw_exceptions' => true]);
});

afterEach(function () {
    Mockery::close();
});

function existingAccount(array $attributes = []): Account
{
    return (new Account)->newFromBuilder(['Id' => '001A', 'NumberOfEmployees' => 10, 'AnnualRevenue' => 100.5] + $attributes);
}

function expectAccountPatch(array $body): void
{
    Forrest::shouldReceive('sobjects')->once()
        ->with('Account/001A', Mockery::on(fn ($args) => $args['method'] === 'patch' && $args['body'] === $body))
        ->andReturn(null);
}

describe('model increment / decrement', function () {
    it('increment() saves the computed value with any extra fields', function () {
        expectAccountPatch(['NumberOfEmployees' => 15, 'Rating' => 'Hot']);

        $account = existingAccount();
        $account->increment('NumberOfEmployees', 5, ['Rating' => 'Hot']);

        expect($account->NumberOfEmployees)->toBe(15);
        expect($account->isDirty())->toBeFalse();
    });

    it('decrement() defaults to a step of 1', function () {
        expectAccountPatch(['NumberOfEmployees' => 9]);

        existingAccount()->decrement('NumberOfEmployees');
    });

    it('incrementEach() / decrementEach() save several fields at once', function () {
        expectAccountPatch(['NumberOfEmployees' => 12, 'AnnualRevenue' => 101.0]);

        existingAccount()->incrementEach(['NumberOfEmployees' => 2, 'AnnualRevenue' => 0.5]);
    });

    it('fires the updating/updated events', function () {
        expectAccountPatch(['NumberOfEmployees' => 11]);
        $fired = [];
        Account::updating(function () use (&$fired) {
            $fired[] = 'updating';
        });
        Account::updated(function () use (&$fired) {
            $fired[] = 'updated';
        });

        existingAccount()->increment('NumberOfEmployees');

        expect($fired)->toBe(['updating', 'updated']);
        Account::flushEventListeners();
    });

    it('throws for a model that has not been saved, instead of updating every record', function () {
        Forrest::shouldReceive('sobjects')->never();

        expect(fn () => (new Account(['NumberOfEmployees' => 1]))->increment('NumberOfEmployees'))
            ->toThrow(InvalidArgumentException::class, 'has not been saved');
    });
});

describe('query increment / decrement', function () {
    it('throws, because Salesforce has no atomic increment', function (string $method, array $args) {
        Forrest::shouldReceive('query')->never();

        expect(fn () => Account::where('Industry', 'Tech')->{$method}(...$args))
            ->toThrow(InvalidArgumentException::class, 'Salesforce has no atomic increment');
    })->with([
        'increment'     => ['increment', ['NumberOfEmployees']],
        'decrement'     => ['decrement', ['NumberOfEmployees', 2]],
        'incrementEach' => ['incrementEach', [['NumberOfEmployees' => 1]]],
        'decrementEach' => ['decrementEach', [['NumberOfEmployees' => 1]]],
    ]);
});

it('decrementEach() saves several fields at once', function () {
    expectAccountPatch(['NumberOfEmployees' => 8, 'AnnualRevenue' => 100.0]);

    existingAccount()->decrementEach(['NumberOfEmployees' => 2, 'AnnualRevenue' => 0.5]);
});
