<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Examples\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope to filter only active records
 * Tests the global scope functionality with Salesforce models
 */
class ActiveScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('IsActive', '=', true);
    }
}
