<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Tests\Unit\Fixtures;

use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;

class ModelWithTableOverride extends SalesforceModel
{
    public function getTable(): string
    {
        return 'Override__c';
    }
}
