<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Database;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class SOQLHasOneOrMany extends Relation
{
    protected static $selfJoinCount = 0;

    public function __construct(
        Builder $query,
        Model $parent,
        protected string $foreignKey,
        protected string $localKey
    ) {
        parent::__construct($query, $parent);
    }

    /**
     * Create and return an unsaved instance of the related model.
     */
    public function make(array $attributes = []): Model
    {
        return tap($this->related->newInstance($attributes), function ($instance): void {
            $this->setForeignAttributesForCreate($instance);
        });
    }

    /**
     * Set the base constraints on the relation query.
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

    /**
     * Match the eagerly loaded results to their single parents.
     */
    public function matchOne(array $models, EloquentCollection $results, string $relation): array
    {
        return $this->matchOneOrMany($models, $results, $relation, 'one');
    }

    /**
     * Match the eagerly loaded results to their many parents.
     */
    public function matchMany(array $models, EloquentCollection $results, string $relation): array
    {
        return $this->matchOneOrMany($models, $results, $relation, 'many');
    }

    /**
     * Match the eagerly loaded results to their many parents.
     */
    protected function matchOneOrMany(array $models, EloquentCollection $results, string $relation, string $type): array
    {
        $dictionary = $this->buildDictionary($results);

        // Once we have the dictionary, we can simply spin through the parent models to
        // link them up with their children using the keyed dictionary to make the
        // matching very convenient and easy work. Then we'll just return them.
        foreach ($models as $model) {
            if (isset($dictionary[$key = $model->getAttribute($this->localKey)])) {
                $model->setRelation(
                    $relation,
                    $this->getRelationValue($dictionary, $key, $type)
                );
            }
        }

        return $models;
    }

    /**
     * Get the value of a relationship by one or many type.
     */
    protected function getRelationValue(array $dictionary, string $key, string $type): mixed
    {
        $value = $dictionary[$key];

        return $type === 'one' ? reset($value) : $this->related->newCollection($value);
    }

    /**
     * Build a model dictionary keyed by the relation's foreign key.
     */
    protected function buildDictionary(EloquentCollection $results): array
    {
        $foreign = $this->getForeignKeyName();

        return $results->mapToDictionary(fn ($result): array => [$result->{$foreign} => $result])->all();
    }

    /**
     * Find a model by its primary key or return a new instance of the related model.
     */
    public function findOrNew($id, $columns = ['*']): Model | Collection | null
    {
        if (is_null($instance = $this->query->find($id, $columns))) {
            $instance = $this->related->newInstance();

            $this->setForeignAttributesForCreate($instance);
        }

        return $instance;
    }

    /**
     * Get the first related model record matching the attributes or instantiate it.
     */
    public function firstOrNew(array $attributes = [], array $values = []): Model
    {
        if (is_null($instance = $this->query->where($attributes)->first())) {
            $instance = $this->related->newInstance($attributes + $values);

            $this->setForeignAttributesForCreate($instance);
        }

        return $instance;
    }

    /**
     * Get the first related record matching the attributes or create it.
     */
    public function firstOrCreate(array $attributes = [], array $values = []): Model
    {
        if (is_null($instance = $this->query->where($attributes)->first())) {
            return $this->create($attributes + $values);
        }

        return $instance;
    }

    /**
     * Create or update a related record matching the attributes, and fill it with values.
     */
    public function updateOrCreate(array $attributes, array $values = []): Model
    {
        return tap($this->firstOrNew($attributes), function ($instance) use ($values): void {
            $instance->fill($values);

            $instance->save();
        });
    }

    /**
     * Attach a model instance to the parent model.
     */
    public function save(Model $model): Model | false
    {
        $this->setForeignAttributesForCreate($model);

        return $model->save() ? $model : false;
    }

    /**
     * Attach a collection of models to the parent instance.
     */
    public function saveMany($models): iterable
    {
        foreach ($models as $model) {
            $this->save($model);
        }

        return $models;
    }

    /**
     * Create a new instance of the related model.
     */
    public function create(array $attributes = []): Model
    {
        return tap($this->related->newInstance($attributes), function ($instance): void {
            $this->setForeignAttributesForCreate($instance);

            $instance->save();
        });
    }

    /**
     * Create a Collection of new instances of the related model.
     */
    public function createMany(array $records): EloquentCollection
    {
        $instances = $this->related->newCollection();

        foreach ($records as $record) {
            $instances->push($this->create($record));
        }

        return $instances;
    }

    /**
     * Set the foreign ID for creating a related model.
     */
    protected function setForeignAttributesForCreate(Model $model): void
    {
        $model->setAttribute($this->getForeignKeyName(), $this->getParentKey());
    }

    /**
     * Perform an update on all the related models.
     */
    public function update(array $values): int
    {
        if ($this->related->usesTimestamps() && $this->relatedUpdatedAt()) {
            $values[$this->relatedUpdatedAt()] = $this->related->freshTimestampString();
        }

        return $this->query->update($values);
    }

    /**
     * Add the constraints for a relationship query.
     *
     * @param  array|mixed  $columns
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*']): Builder
    {
        if ($query->getQuery()->from == $parentQuery->getQuery()->from) {
            return $this->getRelationExistenceQueryForSelfRelation($query, $parentQuery, $columns);
        }

        return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
    }

    /**
     * Add the constraints for a relationship query on the same table.
     */
    public function getRelationExistenceQueryForSelfRelation(Builder $query, Builder $parentQuery, array $columns = ['*']): Builder
    {
        $query->from($query->getModel()->getTable() . ' as ' . $hash = $this->getRelationCountHash());

        $query->getModel()->setTable($hash);

        return $query->select($columns)->whereColumn(
            $this->getQualifiedParentKeyName(),
            '=',
            $hash . '.' . $this->getForeignKeyName()
        );
    }

    /**
     * Get a relationship join table hash.
     */
    public function getRelationCountHash($incrementJoinCount = true): string
    {
        return 'laravel_reserved_' . static::$selfJoinCount++;
    }

    /**
     * Get the key for comparing against the parent key in "has" query.
     */
    public function getExistenceCompareKey(): string
    {
        return $this->getQualifiedForeignKeyName();
    }

    /**
     * Get the key value of the parent's local key.
     */
    public function getParentKey(): mixed
    {
        return $this->parent->getAttribute($this->localKey);
    }

    /**
     * Get the fully qualified parent key name.
     */
    public function getQualifiedParentKeyName(): string
    {
        return $this->parent->qualifyColumn($this->localKey);
    }

    /**
     * Get the plain foreign key.
     */
    public function getForeignKeyName(): string
    {
        $segments = explode('.', $this->getQualifiedForeignKeyName());

        return end($segments) ?: '';
    }

    /**
     * Get the foreign key for the relationship.
     */
    public function getQualifiedForeignKeyName(): string
    {
        return $this->foreignKey;
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

    /**
     * Match the eagerly loaded results to their parents.
     *
     * This method is called during eager loading. It should be overridden
     * by HasOne and HasMany to call matchOne or matchMany respectively.
     *
     * @param  string  $relation
     */
    public function match(array $models, EloquentCollection $results, $relation): array
    {
        // This is called by the eager loading mechanism
        // HasOne and HasMany will override this to call matchOne or matchMany
        return $this->matchOne($models, $results, $relation);
    }

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
     * Get the default value for this relation.
     * For hasOne and hasMany, this should return null when no relation exists.
     */
    public function getDefaultFor($model): mixed
    {
        return null;
    }
}
