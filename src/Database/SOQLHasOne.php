<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Database;

class SOQLHasOne extends SOQLHasOneOrMany
{
    /**
     * Get the results of the relationship.
     */
    public function getResults(): mixed
    {
        if (is_null($this->getParentKey())) {
            return $this->getDefaultFor($this->parent);
        }

        return $this->query->first() ?: $this->getDefaultFor($this->parent);
    }

    /**
     * Initialize the relation on a set of models.
     *
     * @param  string  $relation
     */
    public function initRelation(array $models, $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->getDefaultFor($model));
        }

        return $models;
    }
}
