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

    /**
     * Get the attributes that should be cast.
     *
     * Merges parent casts (CreatedDate, LastModifiedDate) with custom casts.
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'CloseDate' => 'datetime',
        ]);
    }

    public function lineItems()
    {
        return $this->hasMany(ProductLineItem::class, 'Opportunity__c', 'Id');
    }
}
