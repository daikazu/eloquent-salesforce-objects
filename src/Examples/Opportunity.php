<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Examples;

use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;

class Opportunity extends SalesforceModel
{
    public function account()
    {
        return $this->belongsTo(Account::class, 'AccountId');
    }

    protected $casts = [
        'CloseDate' => 'datetime:Y-m-d',
    ];

}
