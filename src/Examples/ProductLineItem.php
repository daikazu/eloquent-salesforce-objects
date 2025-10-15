<?php

namespace Daikazu\EloquentSalesforceObjects\Examples;


use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;

class ProductLineItem extends SalesforceModel
{

    protected $table = 'Opportunity_Product__c';


    public function opportunity()
    {
        return $this->belongsTo(Opportunity::class, 'Opportunity__c', 'Id');
    }

}
