<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Examples;

use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;

class Account extends SalesforceModel
{
    /**
     * Default columns to fetch when querying Accounts
     * Note: Id, CreatedDate, LastModifiedDate, and IsDeleted are automatically included
     *
     * Set to null to fetch all columns (use ['*'])
     */
    protected ?array $defaultColumns = [
        'Name',
        'Type',
        'Industry',
        'Website',
        'Phone',
        'BillingStreet',
        'BillingCity',
        'BillingState',
        'BillingPostalCode',
        'BillingCountry',
        'AnnualRevenue',
        'NumberOfEmployees',
        'OwnerId',
    ];

    public function contacts(): \Daikazu\EloquentSalesforceObjects\Database\SOQLHasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function opportunities(): \Daikazu\EloquentSalesforceObjects\Database\SOQLHasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    //    protected $table = 'Account';

    //    protected function casts(): array
    //    {
    //        return [
    //            'AnnualRevenue' => 'float',
    //            'NumberOfEmployees' => 'int',
    //        ];
    //    }

}
