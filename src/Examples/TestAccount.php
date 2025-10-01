<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Examples;

use Daikazu\EloquentSalesforceObjects\Examples\Scopes\ActiveScope;
use Daikazu\EloquentSalesforceObjects\Examples\Scopes\VerifiedScope;
use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;

/**
 * Test Account model with various scope types
 * Used for testing scope functionality
 */
#[ScopedBy([VerifiedScope::class])]
class TestAccount extends SalesforceModel
{
    protected $table = 'Account';

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        // Add global scope using addGlobalScope (class-based)
        static::addGlobalScope(new ActiveScope);

        // Add anonymous global scope (closure-based)
        static::addGlobalScope('industry_filter', function ($builder): void {
            $builder->whereNotNull('Industry');
        });
    }

    /**
     * Local scope to filter by specific industry
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeIndustry($query, string $industry)
    {
        return $query->where('Industry', '=', $industry);
    }

    /**
     * Local scope to filter by annual revenue greater than amount
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRevenueAbove($query, float $amount)
    {
        return $query->where('AnnualRevenue', '>', $amount);
    }

    /**
     * Local scope to filter active accounts (local scope version)
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('IsActive', '=', true);
    }

    /**
     * Local scope to filter by type
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('Type', '=', $type);
    }
}
