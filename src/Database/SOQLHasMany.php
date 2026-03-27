<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Database;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends HasMany<TRelatedModel, TDeclaringModel>
 */
class SOQLHasMany extends HasMany
{
    /**
     * Set the base constraints on the relation query.
     *
     * SOQL does not support whereNotNull, so we omit it.
     */
    public function addConstraints(): void
    {
        if (static::$constraints) {
            $this->query->where($this->foreignKey, '=', $this->getParentKey());
        }
    }

    /**
     * Set the constraints for an eager load of the relation.
     */
    public function addEagerConstraints(array $models): void
    {
        $this->query->whereIn(
            $this->foreignKey,
            $this->getKeys($models, $this->localKey)
        );
    }
}
