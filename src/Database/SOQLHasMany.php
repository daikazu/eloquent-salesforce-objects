<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Database;

class SOQLHasMany extends SOQLHasOneOrMany
{
    /**
     * Get the results of the relationship.
     */
    public function getResults(): mixed
    {
        return is_null($this->getParentKey())
            ? $this->related->newCollection()
            : $this->query->get();
    }

    /**
     * Initialize the relation on a set of models.
     *
     * @param  string  $relation
     */
    public function initRelation(array $models, $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }
}
