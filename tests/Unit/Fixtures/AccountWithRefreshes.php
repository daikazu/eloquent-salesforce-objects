<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Tests\Unit\Fixtures;

use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;
use Illuminate\Database\Eloquent\Attributes\Refreshes;

/**
 * Laravel 13.33+ re-selects #[Refreshes] fields after each save.
 */
#[Refreshes(['Rating_Formula__c'])]
class AccountWithRefreshes extends SalesforceModel
{
    protected $table = 'Account';
}
