<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Tests\Unit\Fixtures;

use Daikazu\EloquentSalesforceObjects\Examples\Contact;
use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AccountWithPrimaryContact extends SalesforceModel
{
    protected $table = 'Account';

    public function primaryContact(): HasOne
    {
        return $this->hasOne(Contact::class, 'AccountId');
    }
}
