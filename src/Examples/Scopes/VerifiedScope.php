<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Examples\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope to filter only verified accounts
 * Used for testing ScopedBy attribute functionality
 */
class VerifiedScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('IsVerified__c', '=', true);
    }
}
