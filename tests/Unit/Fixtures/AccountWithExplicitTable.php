<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Tests\Unit\Fixtures;

use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;

class AccountWithExplicitTable extends SalesforceModel
{
    protected $table = 'Account__c';
}
