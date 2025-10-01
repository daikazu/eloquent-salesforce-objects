<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Examples;

use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;

class Contact extends SalesforceModel
{
    public function account()
    {
        return $this->belongsTo(Account::class, 'AccountId');
    }
}
