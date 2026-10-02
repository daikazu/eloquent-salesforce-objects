<?php

/**
 * Eager loading (with()) tests. See
 * docs/superpowers/specs/2026-10-02-with-subquery-eager-loading-design.md.
 *
 * fakeSalesforceTables() describes objects without childRelationships, so those
 * tests exercise the fallback strategy: one extra query per relationship,
 * constrained by "<foreign key> in (<parent ids>)". The subquery strategy tests
 * at the bottom use describe data that includes childRelationships.
 */

use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Daikazu\EloquentSalesforceObjects\Examples\Contact;
use Daikazu\EloquentSalesforceObjects\Examples\Opportunity;
use Daikazu\EloquentSalesforceObjects\Tests\Unit\Fixtures\AccountWithPrimaryContact;
use Illuminate\Support\Facades\Cache;
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

    it('splits the parent Ids into groups of 200, one query per group', function () {
        $accounts = array_map(fn ($i) => ['Id' => sprintf('001xx%013d', $i), 'Name' => "A{$i}"], range(1, 450));
        $contacts = array_map(fn ($i) => ['Id' => sprintf('003xx%013d', $i), 'AccountId' => sprintf('001xx%013d', $i)], range(1, 450));
        $queries = fakeSalesforceTables(['Account' => $accounts, 'Contact' => $contacts]);

        $loaded = Account::with('contacts')->get();

        $contactQueries = array_values(array_filter($queries->getArrayCopy(), fn ($q) => str_contains($q, ' from Contact ')));
        expect($contactQueries)->toHaveCount(3);
        expect(array_map(fn ($q) => substr_count($q, "'001xx"), $contactQueries))->toBe([200, 200, 50]);

        expect($loaded)->toHaveCount(450);
        expect($loaded->every(fn ($account) => $account->contacts->count() === 1
            && $account->contacts->first()->AccountId === $account->Id))->toBeTrue();
    });

    it('splits the Ids for load() on an existing collection too', function () {
        $accounts = array_map(fn ($i) => ['Id' => sprintf('001xx%013d', $i)], range(1, 450));
        $queries = fakeSalesforceTables(['Account' => $accounts, 'Contact' => []]);

        Account::get()->load('contacts');

        expect($queries)->toHaveCount(4);
    });

    it('splits the Ids for belongsTo', function () {
        $contacts = array_map(fn ($i) => ['Id' => sprintf('003xx%013d', $i), 'AccountId' => sprintf('001xx%013d', $i)], range(1, 450));
        $accounts = array_map(fn ($i) => ['Id' => sprintf('001xx%013d', $i), 'Name' => "A{$i}"], range(1, 450));
        $queries = fakeSalesforceTables(['Contact' => $contacts, 'Account' => $accounts]);

        $loaded = Contact::with('account')->get();

        expect($queries)->toHaveCount(4);
        expect($loaded->last()->account->Name)->toBe('A450');
    });

    it('throws a clear error for limit() in the closure, since SOQL has no per-parent limit outside a subquery', function () {
        $queries = fakeSalesforceTables(eagerTables());

        expect(fn () => Account::with(['contacts' => fn ($q) => $q->limit(1)])->get())
            ->toThrow(InvalidArgumentException::class, 'limit() on an eager-loaded relationship');

        expect($queries)->toHaveCount(1);
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

// ===========================================================================
// Subquery strategy: children come back nested in the parent query
// ===========================================================================

/**
 * Describe data with childRelationships, plus a query fake that returns
 * $response for every query and records the SOQL it receives.
 */
function fakeSubquerySalesforce(array ...$responses): ArrayObject
{
    $queries = new ArrayObject;

    Forrest::shouldReceive('hasToken')->andReturn(true);
    Forrest::shouldReceive('describe')->andReturnUsing(fn ($object) => [
        'fields'             => array_map(fn ($name) => ['name' => $name], ['Id', 'Name', 'AccountId', 'LastName', 'Opportunity__c']),
        'childRelationships' => match ($object) {
            'Account' => [
                ['childSObject' => 'Contact', 'field' => 'AccountId', 'relationshipName' => 'Contacts'],
                ['childSObject' => 'Opportunity', 'field' => 'AccountId', 'relationshipName' => 'Opportunities'],
            ],
            'Opportunity' => [
                ['childSObject' => 'Opportunity_Product__c', 'field' => 'Opportunity__c', 'relationshipName' => 'Line_Items__r'],
            ],
            default => [],
        },
    ]);

    Forrest::shouldReceive('query')->andReturnUsing(function (string $soql) use ($queries, &$responses) {
        $queries[] = $soql;

        $records = array_shift($responses) ?? [];

        return ['totalSize' => count($records), 'done' => true, 'records' => $records];
    });

    return $queries;
}

/** A nested relationship result, as Salesforce returns it inside a parent row. */
function nested(array $records): ?array
{
    return $records === [] ? null : ['totalSize' => count($records), 'done' => true, 'records' => $records];
}

function accountsWithContacts(): array
{
    return [
        ['Id' => '001A', 'Name' => 'Acme', 'Contacts' => nested([
            ['Id' => '003A', 'LastName' => 'Adams', 'AccountId' => '001A'],
            ['Id' => '003B', 'LastName' => 'Baker', 'AccountId' => '001A'],
        ])],
        ['Id' => '001C', 'Name' => 'Initech', 'Contacts' => null],
    ];
}

describe('with() — subquery strategy', function () {
    beforeEach(function () {
        Cache::flush();
    });

    it('loads children in the same query as the parents', function () {
        $queries = fakeSubquerySalesforce(accountsWithContacts());

        $accounts = Account::with('contacts')->get()->keyBy('Id');

        expect($queries)->toHaveCount(1);
        expect($queries[0])->toContain(', (select Id, CreatedDate, LastModifiedDate, IsDeleted, Name, AccountId, LastName, Opportunity__c from Contacts) from Account');
        expect($accounts['001A']->contacts->pluck('LastName')->all())->toBe(['Adams', 'Baker']);
        expect($accounts['001A']->contacts->first())->toBeInstanceOf(Contact::class);
        expect($accounts['001C']->relationLoaded('contacts'))->toBeTrue();
        expect($accounts['001C']->contacts)->toHaveCount(0);
    });

    it('keeps the raw nested result out of the parent attributes', function () {
        fakeSubquerySalesforce(accountsWithContacts());

        $account = Account::with('contacts')->get()->first();

        expect($account->getAttributes())->not->toHaveKey('Contacts');
        expect($account->getOriginal())->not->toHaveKey('Contacts');
        expect($account->isDirty())->toBeFalse();
        expect($account->toArray()['contacts'])->toHaveCount(2);
    });

    it('compiles closure constraints into the subquery, with escaping', function () {
        $queries = fakeSubquerySalesforce(accountsWithContacts());

        Account::with(['contacts' => fn ($q) => $q->select(['Id', 'LastName'])
            ->where('LastName', '!=', "O'Brien")
            ->orderBy('LastName')])->get();

        expect($queries[0])->toContain("(select Id, LastName from Contacts where LastName != 'O\\'Brien' order by LastName asc)");
    });

    it('applies limit() per parent', function () {
        $queries = fakeSubquerySalesforce(accountsWithContacts());

        Account::with(['contacts' => fn ($q) => $q->select(['Id'])->orderBy('LastName')->limit(2)])->get();

        expect($queries[0])->toContain('(select Id from Contacts order by LastName asc limit 2)');
        expect($queries[0])->not->toContain('row_number');
    });

    it('loads hasOne with a limit of 1 and returns the child or null', function () {
        $queries = fakeSubquerySalesforce(accountsWithContacts());

        $accounts = AccountWithPrimaryContact::with(['primaryContact' => fn ($q) => $q->select(['Id', 'LastName'])])->get()->keyBy('Id');

        expect($queries)->toHaveCount(1);
        expect($queries[0])->toContain('(select Id, LastName from Contacts limit 1)');
        expect($accounts['001A']->primaryContact->LastName)->toBe('Adams');
        expect($accounts['001C']->primaryContact)->toBeNull();
    });

    it('uses the relationship name from describe for custom objects', function () {
        $queries = fakeSubquerySalesforce([
            ['Id' => '006A', 'Name' => 'Deal', 'Line_Items__r' => nested([
                ['Id' => 'a01A', 'Name' => 'Widget', 'Opportunity__c' => '006A'],
            ])],
        ]);

        $opportunity = Opportunity::with(['lineItems' => fn ($q) => $q->select(['Id', 'Name'])])->get()->first();

        expect($queries)->toHaveCount(1);
        expect($queries[0])->toContain('(select Id, Name from Line_Items__r) from Opportunity');
        expect($opportunity->lineItems->pluck('Name')->all())->toBe(['Widget']);
    });

    it('loads several relationships in one query', function () {
        $queries = fakeSubquerySalesforce([
            ['Id' => '001A', 'Contacts' => nested([['Id' => '003A']]), 'Opportunities' => nested([['Id' => '006A'], ['Id' => '006B']])],
        ]);

        $account = Account::select(['Id'])->with([
            'contacts'      => fn ($q) => $q->select(['Id']),
            'opportunities' => fn ($q) => $q->select(['Id']),
        ])->get()->first();

        expect($queries)->toHaveCount(1);
        expect($queries[0])->toBe('select Id, (select Id from Contacts), (select Id from Opportunities) from Account');
        expect($account->contacts)->toHaveCount(1);
        expect($account->opportunities)->toHaveCount(2);
    });

    it('loads the next level of a nested relationship with one more query', function () {
        $queries = fakeSubquerySalesforce(
            [['Id' => '001A', 'Opportunities' => nested([['Id' => '006A', 'AccountId' => '001A']])]],
            [['Id' => 'a01A', 'Name' => 'Widget', 'Opportunity__c' => '006A']],
        );

        $account = Account::select(['Id'])->with('opportunities.lineItems')->get()->first();

        expect($queries)->toHaveCount(2);
        expect($queries[0])->toContain('from Opportunities) from Account');
        expect($queries[1])->toEndWith(" from Opportunity_Product__c where Opportunity__c in ('006A')");
        expect($account->opportunities->first()->lineItems->pluck('Name')->all())->toBe(['Widget']);
    });

    it('keeps with() on the builder, so running it twice loads both times', function () {
        $queries = fakeSubquerySalesforce(accountsWithContacts(), accountsWithContacts());

        $builder = Account::with('contacts');
        $builder->get();
        $second = $builder->get()->first();

        expect($queries)->toHaveCount(2);
        expect($queries[1])->toContain('from Contacts)');
        expect($second->contacts)->toHaveCount(2);
    });

    it('works with first()', function () {
        $queries = fakeSubquerySalesforce(array_slice(accountsWithContacts(), 0, 1));

        $account = Account::with('contacts')->first();

        expect($queries)->toHaveCount(1);
        expect($queries[0])->toEndWith('from Contacts) from Account limit 1');
        expect($account->contacts)->toHaveCount(2);
    });

    it('loads every child when Salesforce pages a parent\'s children', function () {
        fakeSubquerySalesforce([
            ['Id' => '001A', 'Contacts' => [
                'done'           => false,
                'nextRecordsUrl' => '/services/data/v64.0/query/01gA-2',
                'records'        => [['Id' => '003A']],
            ]],
        ]);
        Forrest::shouldReceive('next')->once()->with('/services/data/v64.0/query/01gA-2')
            ->andReturn(['done' => true, 'records' => [['Id' => '003B'], ['Id' => '003C']]]);

        $account = Account::select(['Id'])->with('contacts')->get()->first();

        expect($account->contacts->pluck('Id')->all())->toBe(['003A', '003B', '003C']);
    });

    it('falls back to a separate query when the closure uses offset()', function () {
        $queries = fakeSubquerySalesforce(accountsWithContacts(), []);

        Account::with(['contacts' => fn ($q) => $q->offset(5)])->get();

        expect($queries)->toHaveCount(2);
        expect($queries[0])->not->toContain('from Contacts)');
        expect($queries[1])->toContain(" from Contact where AccountId in ('001A', '001C')");
    });

    it('falls back to a separate query when eager_load_strategy is "query"', function () {
        config(['eloquent-salesforce-objects.eager_load_strategy' => 'query']);
        $queries = fakeSubquerySalesforce(accountsWithContacts(), []);

        Account::with('contacts')->get();

        expect($queries)->toHaveCount(2);
        expect($queries[1])->toContain(" from Contact where AccountId in ('001A', '001C')");
    });

    it('does not eager load anything for exists()', function () {
        $queries = fakeSubquerySalesforce([['Id' => '001A']]);

        expect(Account::with('contacts')->exists())->toBeTrue();
        expect($queries)->toHaveCount(1);
        expect($queries[0])->toBe('select Id from Account limit 1');
    });
});
