<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Models;

use Daikazu\EloquentSalesforceObjects\Contracts\AdapterInterface;
use Daikazu\EloquentSalesforceObjects\Database\SOQLBuilder;
use Daikazu\EloquentSalesforceObjects\Database\SOQLHasMany;
use Daikazu\EloquentSalesforceObjects\Database\SOQLHasOne;
use Daikazu\EloquentSalesforceObjects\Models\Concerns\DeletesSalesforceRecords;
use Daikazu\EloquentSalesforceObjects\Models\Concerns\HasSalesforceMetadata;
use Daikazu\EloquentSalesforceObjects\Models\Concerns\SavesSalesforceRecords;
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;

class SalesforceModel extends Model
{
    use DeletesSalesforceRecords;
    use HasSalesforceMetadata;
    use SavesSalesforceRecords;

    const string UPDATED_AT = 'LastModifiedDate';
    const string CREATED_AT = 'CreatedDate';
    const string DATED_FORMAT = 'Y-m-d\TH:i:s.vO';

    /**
     * The default columns to select when querying this model.
     * If null, all columns (*) will be selected.
     * Note: 'Id' is always automatically included.
     */
    protected ?array $defaultColumns = null;

    protected $primaryKey = 'Id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $wasRecentlyCreated = false;

    protected $dateFormat = self::DATED_FORMAT;
    public $timestamps = false;
    protected $connection;
    protected $guarded = [];

    protected array $readOnly = [];

    //    private array $readFields = [
    //        'Id',
    //        'attributes',
    //    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->markAsExistingIfPrimaryKeyPresent($attributes);

        $this->setSalesforceAttributes();
    }

    /**
     * Get writable attributes excluding read-only fields todo: check if this is needed
     */
    public function writeableAttributes(array $exclude = []): array
    {
        $fields = array_merge($this->readOnly, $exclude);

        return Arr::except($this->attributes, $fields);
    }

    /**
     * Get the Salesforce adapter instance
     */
    protected function getSalesforceAdapter(): AdapterInterface
    {
        return app(AdapterInterface::class);
    }

    /**
     * Create a new Eloquent query builder for the model using SOQL
     *
     * @param  QueryBuilder  $query
     */
    public function newEloquentBuilder($query): SOQLBuilder
    {
        $adapter = app(SalesforceAdapter::class);
        return new SOQLBuilder($adapter, $query);
    }

    /**
     * Get a new query builder instance for the connection
     */
    protected function newBaseQueryBuilder(): QueryBuilder
    {
        return new QueryBuilder(
            $this->getConnection(),
            $this->getConnection()->getQueryGrammar(),
            $this->getConnection()->getPostProcessor()
        );
    }

    private function markAsExistingIfPrimaryKeyPresent(array $attributes): void
    {
        if (isset($attributes[$this->primaryKey])) {
            $this->exists = true;
        }
    }

    private function setSalesforceAttributes(): void
    {
        $this->attributes['attributes'] = [
            'type' => $this->getTable(),
        ];
    }

    public function getTable(): string
    {
        return $this->table ?? class_basename($this);
    }

    /**
     * Get the default columns to select when querying this model.
     * Returns null if no defaults are set (will use * in queries).
     */
    public function getDefaultColumns(): ?array
    {
        return $this->defaultColumns;
    }

    /**
     * Instantiate a new HasMany relationship using SOQL.
     *
     * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
     * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $parent
     * @return HasMany<TRelatedModel, TDeclaringModel>
     */
    protected function newHasMany(Builder $query, Model $parent, $foreignKey, $localKey)
    {
        return new SOQLHasMany($query, $parent, $foreignKey, $localKey);
    }

    /**
     * Instantiate a new HasOne relationship using SOQL.
     *
     * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
     * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $parent
     * @return HasOne<TRelatedModel, TDeclaringModel>
     */
    protected function newHasOne(Builder $query, Model $parent, $foreignKey, $localKey)
    {
        return new SOQLHasOne($query, $parent, $foreignKey, $localKey);
    }

    /**
     * Get the default foreign key name for Salesforce relationships.
     * Salesforce uses PascalCase format: AccountId, OwnerId, etc.
     */
    public function getForeignKey(): string
    {
        return $this->getTable() . 'Id';
    }

    /**
     * Qualify a column name for SOQL.
     * SOQL does not use table-qualified column names, so return unqualified.
     */
    public function qualifyColumn($column): string
    {
        return $column;
    }

    /**
     * Get the attributes that should be cast.
     *
     * Salesforce timestamps are automatically cast to Carbon instances,
     * allowing you to use methods like diffForHumans(), format(), etc.
     * The timezone will be converted to your app's configured timezone.
     */
    protected function casts(): array
    {
        return [
            self::CREATED_AT     => 'datetime',
            self::UPDATED_AT     => 'datetime',
            'SystemModstamp'     => 'datetime',
            'LastViewedDate'     => 'datetime',
            'LastReferencedDate' => 'datetime',
        ];
    }

    /**
     * Prepare a date for array / JSON serialization.
     *
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format($this->getDateFormat());
    }
}
