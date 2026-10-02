<?php

/**
 * Characterization tests for eager loading (with()) as it works today: one
 * extra query per relationship, constrained by "<foreign key> in (<parent ids>)".
 *
 * Phase 0 of docs/superpowers/specs/2026-10-02-with-subquery-eager-loading-design.md.
 * These pin current behaviour so the subquery strategy can be checked against it.
 */

use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Daikazu\EloquentSalesforceObjects\Examples\Contact;
use Daikazu\EloquentSalesforceObjects\Examples\Opportunity;
use Daikazu\EloquentSalesforceObjects\Tests\Unit\Fixtures\AccountWithPrimaryContact;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

beforeEach(function () {
    $forrestMock = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
    $this->app->instance('forrest', $forrestMock);
    Forrest::swap($forrestMock);
});

afterEach(function () {
    Mockery::close();
});

/**
 * Fake Salesforce with in-memory tables. Answers "from X" queries with X's rows,
 * honours a "<field> in (...)" filter, and records every SOQL statement it receives.
 *
 * @param  array<string, array<int, array<string, mixed>>>  $tables
 */
function fakeSalesforceTables(array $tables): ArrayObject
{
    $queries = new ArrayObject;

    Forrest::shouldReceive('hasToken')->andReturn(true);
    Forrest::shouldReceive('describe')->andReturn([
        'fields' => array_map(fn ($name) => ['name' => $name], [
            'Id', 'Name', 'AccountId', 'LastName', 'Email', 'Opportunity__c',
        ]),
    ]);

    Forrest::shouldReceive('query')->andReturnUsing(function (string $soql) use ($tables, $queries) {
        $queries[] = $soql;

        preg_match('/ from (\w+)/', $soql, $from);
        $rows = $tables[$from[1]] ?? [];

        if (preg_match('/where (\w+) in \(([^)]*)\)/', $soql, $in)) {
            $values = array_map(fn ($v) => trim($v, " '"), explode(',', $in[2]));
            $rows = array_values(array_filter($rows, fn ($row) => in_array($row[$in[1]] ?? null, $values, true)));
        }

        return [
            'totalSize' => count($rows),
            'done'      => true,
            'records'   => array_map(fn ($row) => $row + ['attributes' => ['type' => $from[1]]], $rows),
        ];
    });

    return $queries;
}

function eagerTables(): array
{
    return [
        'Account' => [
            ['Id' => '001A', 'Name' => 'Acme'],
            ['Id' => '001B', 'Name' => 'Globex'],
            ['Id' => '001C', 'Name' => 'Initech'],
        ],
        'Contact' => [
            ['Id' => '003A', 'LastName' => 'Adams', 'AccountId' => '001A'],
            ['Id' => '003B', 'LastName' => 'Baker', 'AccountId' => '001A'],
            ['Id' => '003C', 'LastName' => 'Clark', 'AccountId' => '001B'],
            ['Id' => '003D', 'LastName' => 'Doe', 'AccountId' => null],
        ],
        'Opportunity' => [
            ['Id' => '006A', 'Name' => 'Deal', 'AccountId' => '001A'],
        ],
        'Opportunity_Product__c' => [
            ['Id' => 'a01A', 'Name' => 'Widget', 'Opportunity__c' => '006A'],
            ['Id' => 'a01B', 'Name' => 'Gadget', 'Opportunity__c' => '006A'],
        ],
    ];
}

describe('with() — hasMany', function () {
    it('runs one extra query constrained by the parent Ids', function () {
        $queries = fakeSalesforceTables(eagerTables());

        Account::with('contacts')->get();

        expect($queries)->toHaveCount(2);
        expect($queries[0])->toContain(' from Account');
        expect($queries[1])->toEndWith(" from Contact where AccountId in ('001A', '001B', '001C')");
    });

    it('matches children to their parent and gives childless parents an empty collection', function () {
        fakeSalesforceTables(eagerTables());

        $accounts = Account::with('contacts')->get()->keyBy('Id');

        expect($accounts['001A']->relationLoaded('contacts'))->toBeTrue();
        expect($accounts['001A']->contacts->pluck('LastName')->all())->toBe(['Adams', 'Baker']);
        expect($accounts['001B']->contacts->pluck('LastName')->all())->toBe(['Clark']);
        expect($accounts['001C']->contacts)->toHaveCount(0);
        expect($accounts['001A']->contacts->first())->toBeInstanceOf(Contact::class);
    });

    it('does not query the relationship when there are no parents', function () {
        $queries = fakeSalesforceTables(['Account' => []]);

        expect(Account::with('contacts')->get())->toHaveCount(0);
        expect($queries)->toHaveCount(1);
    });

    it('uses a custom foreign key and object name', function () {
        $queries = fakeSalesforceTables(eagerTables());

        $opportunity = Opportunity::with('lineItems')->get()->first();

        expect($queries[1])->toEndWith(" from Opportunity_Product__c where Opportunity__c in ('006A')");
        expect($opportunity->lineItems->pluck('Name')->all())->toBe(['Widget', 'Gadget']);
    });

    it('appends closure constraints after the parent Id filter', function () {
        $queries = fakeSalesforceTables(eagerTables());

        Account::with(['contacts' => fn ($q) => $q->where('Email', '!=', null)->orderBy('LastName')])->get();

        expect($queries[1])->toEndWith(
            " from Contact where AccountId in ('001A', '001B', '001C') and Email != null order by LastName asc"
        );
    });

    it('honours select() in the closure', function () {
        $queries = fakeSalesforceTables(eagerTables());

        Account::with(['contacts' => fn ($q) => $q->select(['Id', 'LastName', 'AccountId'])])->get();

        expect($queries[1])->toStartWith('select Id, LastName, AccountId from Contact');
    });

    it('runs one query per relationship when loading several', function () {
        $queries = fakeSalesforceTables(eagerTables());

        $account = Account::with(['contacts', 'opportunities'])->get()->firstWhere('Id', '001A');

        expect($queries)->toHaveCount(3);
        expect($account->contacts)->toHaveCount(2);
        expect($account->opportunities)->toHaveCount(1);
    });

    it('runs one query per level for nested relationships', function () {
        $queries = fakeSalesforceTables(eagerTables());

        $account = Account::with('opportunities.lineItems')->get()->firstWhere('Id', '001A');

        expect($queries)->toHaveCount(3);
        expect($queries[1])->toEndWith(" from Opportunity where AccountId in ('001A', '001B', '001C')");
        expect($queries[2])->toEndWith(" from Opportunity_Product__c where Opportunity__c in ('006A')");
        expect($account->opportunities->first()->lineItems)->toHaveCount(2);
    });

    it('embeds every parent Id in the eager query, so its length grows with the parent count', function () {
        $accounts = array_map(fn ($i) => ['Id' => sprintf('001xx%013d', $i), 'Name' => "A{$i}"], range(1, 1000));
        $queries = fakeSalesforceTables(['Account' => $accounts, 'Contact' => []]);

        Account::with('contacts')->get();

        // 18-char Id + 2 quotes + ", " separator = 22 characters per parent
        expect(strlen($queries[1]))->toBeGreaterThan(1000 * 22);
    });

    // Known bug, fixed by phase 1: Laravel turns limit() inside an eager-load
    // closure into a row_number() window function, which SOQL doesn't have.
    it('compiles limit() in the closure to a window function Salesforce rejects', function () {
        $queries = fakeSalesforceTables(eagerTables());

        try {
            Account::with(['contacts' => fn ($q) => $q->limit(1)])->get();
        } catch (Throwable) {
            // The fake can't parse the result either; the SOQL sent is what matters
        }

        expect($queries[1] ?? '')->toContain('row_number() over (partition by');
    });
});

describe('with() — hasOne', function () {
    it('loads the first matching child, or null', function () {
        $queries = fakeSalesforceTables(eagerTables());

        $accounts = AccountWithPrimaryContact::with('primaryContact')->get()->keyBy('Id');

        expect($queries[1])->toContain(" from Contact where AccountId in ('001A', '001B', '001C')");
        expect($accounts['001B']->primaryContact->LastName)->toBe('Clark');
        expect($accounts['001C']->primaryContact)->toBeNull();
    });
});

describe('with() — belongsTo', function () {
    it('loads parents with one query on their distinct, non-null Ids', function () {
        $queries = fakeSalesforceTables(eagerTables());

        $contacts = Contact::with('account')->get()->keyBy('Id');

        expect($queries)->toHaveCount(2);
        expect($queries[1])->toEndWith(" from Account where Id in ('001A', '001B')");
        expect($contacts['003A']->account->Name)->toBe('Acme');
        expect($contacts['003C']->account->Name)->toBe('Globex');
        expect($contacts['003D']->account)->toBeNull();
    });
});

describe('load() on an existing collection', function () {
    it('eager loads the same way as with()', function () {
        $queries = fakeSalesforceTables(eagerTables());

        $accounts = Account::get();
        $accounts->load('contacts');

        expect($queries)->toHaveCount(2);
        expect($queries[1])->toEndWith(" from Contact where AccountId in ('001A', '001B', '001C')");
        expect($accounts->firstWhere('Id', '001A')->contacts)->toHaveCount(2);
    });
});
