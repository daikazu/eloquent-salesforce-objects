<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Database;

use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SOQLGrammar extends Grammar
{
    protected ?SalesforceModel $model = null;

    /**
     * The components that make up a select clause.
     *
     * @var string[]
     */
    protected $selectComponents = [
        'aggregate',
        'columns',
        'joins',
        'from',
        'wheres',
        'groups',
        'havings',
        'orders',
        'limit',
        'offset',
        'lock',
        'for',
    ];

    public function getModel(): ?SalesforceModel
    {
        return $this->model;
    }

    public function setModel(SalesforceModel $model): SalesforceModel
    {
        $this->model = $model;
        return $model;
    }

    /**
     * Wrap a single string in keyword identifiers.
     *
     * @param  string  $value
     */
    protected function wrapValue($value): string
    {
        return $value;
    }

    /**
     * {@inheritdoc}
     *
     * @param  array  $where
     */
    protected function whereBasic(Builder $query, $where): string
    {
        if ($this->isDate($where['column'])) {
            return $this->whereDate($query, $where);
        }

        // allow for "false" values to not be wrapped.
        if (is_bool($where['value'])) {
            return $this->whereBoolean($where);
        }

        // allow for literal string values
        if (is_string($where['value']) && $this->checkStringLiteral($where['value'])) {

            return $this->whereLiteral($query, $where);
        }

        if (Str::contains(strtolower((string) $where['operator']), 'not like')) {
            return sprintf(
                '(not %s like %s)',
                $this->wrap($where['column']),
                $this->parameter($where['value'])
            );
        }

        return parent::whereBasic($query, $where);
    }

    protected function whereDate(Builder $query, $where): string
    {
        return $this->wrap($where['column']) . $where['operator'] . '?';
    }

    protected function compileLimit(Builder $query, $limit): string
    {
        return 'limit ' . (int) $limit;
    }

    protected function isDate($column): bool
    {
        return $this->model !== null && in_array($column, $this->model->getDates());
    }

    public function parameter($value, $column = null): string
    {
        // Numeric values (int and float) are not quoted in SOQL
        if (is_int($value) || is_float($value)) {
            return '?';
        }

        // String values are quoted in SOQL
        if (is_string($value)) {
            return "'?'";
        }

        return $this->isExpression($value) ? $this->getValue($value) : '?';
    }

    /**
     * An empty IN list matches nothing. Every record has an Id, so "Id = null" is always false.
     */
    protected function whereIn(Builder $query, $where): string
    {
        if (! empty($where['values'])) {
            return $this->wrap($where['column']) . ' in (' . $this->parameterize($where['values']) . ')';
        }

        return 'Id = null';
    }

    /**
     * An empty NOT IN list matches everything; "Id != null" is always true. (SQL's "1 = 1" isn't valid SOQL.)
     */
    protected function whereNotIn(Builder $query, $where): string
    {
        if (! empty($where['values'])) {
            return $this->wrap($where['column']) . ' not in (' . $this->parameterize($where['values']) . ')';
        }

        return 'Id != null';
    }

    protected function whereInRaw(Builder $query, $where): string
    {
        return empty($where['values']) ? 'Id = null' : parent::whereInRaw($query, $where);
    }

    protected function whereNotInRaw(Builder $query, $where): string
    {
        return empty($where['values']) ? 'Id != null' : parent::whereNotInRaw($query, $where);
    }

    /**
     * SOQL has no BETWEEN, so compile to a pair of comparisons.
     */
    protected function whereBetween(Builder $query, $where): string
    {
        $values = array_values(is_array($where['values']) ? $where['values'] : iterator_to_array($where['values']));
        $column = $this->wrap($where['column']);

        // Date columns take unquoted literals, as in whereBasic()
        [$min, $max] = $this->isDate($where['column'])
            ? ['?', '?']
            : [$this->parameter($values[0]), $this->parameter($values[count($values) - 1])];

        return $where['not']
            ? "({$column} < {$min} or {$column} > {$max})"
            : "({$column} >= {$min} and {$column} <= {$max})";
    }

    protected function whereBetweenColumns(Builder $query, $where): string
    {
        throw new InvalidArgumentException(self::COLUMN_COMPARISON_UNSUPPORTED);
    }

    protected function whereValueBetween(Builder $query, $where): string
    {
        throw new InvalidArgumentException(self::COLUMN_COMPARISON_UNSUPPORTED);
    }

    public function compileRandom($seed): string
    {
        throw new InvalidArgumentException('SOQL has no random ordering. Shuffle the results in PHP instead: ->get()->shuffle().');
    }

    protected function compileColumns(Builder $query, $columns): ?string
    {
        if ($query->aggregate === null && $query->distinct) {
            throw new InvalidArgumentException(
                'SOQL has no DISTINCT. Use groupBy() on the field instead, or ->distinct()->count(\'Field\') for COUNT_DISTINCT().'
            );
        }

        return parent::compileColumns($query, $columns);
    }

    /**
     * SOQL has no joins. It can only follow relationships Salesforce defines, and
     * child records come back nested rather than as flat joined rows.
     */
    public const string JOINS_UNSUPPORTED = 'SOQL does not support joins. '
        . 'Load child records with ->with(\'contacts\'), select parent fields with dot notation (->select(\'Account.Name\')), '
        . 'or filter by related records with a semi-join: ->whereIn(\'Id\', fn ($q) => $q->select(\'AccountId\')->from(\'Contact\')->where(...)).';

    public const string COLUMN_COMPARISON_UNSUPPORTED = 'SOQL does not support column-to-column comparisons '
        . '(whereColumn, whereBetweenColumns, has, whereHas, doesntHave, withCount). '
        . 'Use a semi-join instead: ->whereIn(\'Id\', fn ($q) => $q->select(\'Lookup__c\')->from(\'Child__c\')->where(...)).';

    /**
     * Reject joins that reach the grammar without going through SOQLBuilder.
     *
     * @param  array  $joins
     */
    protected function compileJoins(Builder $query, $joins): string
    {
        throw new InvalidArgumentException(self::JOINS_UNSUPPORTED);
    }

    /**
     * limit() inside with() asks for a per-parent limit. SOQL can only do that in a
     * child subquery, so reject it when the relationship falls back to a plain query.
     */
    protected function compileGroupLimit(Builder $query): string
    {
        throw new InvalidArgumentException(
            'limit() on an eager-loaded relationship only works when it loads through a SOQL child subquery, '
            . 'and this one could not: eager_load_strategy is "query", the relationship is not in Salesforce\'s '
            . 'child relationships for the parent, or the closure uses offset(), grouping or distinct. '
            . 'Remove limit() and trim the loaded collection instead.'
        );
    }

    protected function concatenateWhereClauses($query, $sql): string
    {
        $conjunction = 'where';
        return $conjunction . ' ' . $this->removeLeadingBoolean(implode(' ', $sql));
    }

    protected function compileAggregate(Builder $query, $aggregate): string
    {
        $column = $this->columnize($aggregate['columns']);

        // SOQL doesn't support COUNT(*) or other aggregates with *
        // For COUNT, use COUNT() to count all records
        // For other aggregates with *, use Id as the default field
        if ($column === '*') {
            if (strtolower($aggregate['function']) === 'count') {
                $column = '';  // COUNT() with no parameter counts all records
            } else {
                $column = 'Id';  // Other aggregates need a field
            }
        }

        $function = strtoupper($aggregate['function']);

        // SOQL spells a distinct count COUNT_DISTINCT(field); other aggregates have no distinct form
        if ($query->distinct && $column !== '') {
            if ($function !== 'COUNT') {
                throw new InvalidArgumentException("SOQL has no distinct form of {$function}().");
            }

            $function = 'COUNT_DISTINCT';
        }

        // SOQL assigns aliases (expr0, expr1, ...) to aggregate results itself
        $function .= '(' . $column . ')';

        return 'select ' . $function;
    }

    protected function whereNotNull(Builder $query, $where): string
    {
        // SOQL uses the != operator for not-null checks
        return $this->wrap($where['column']) . ' != null';
    }

    protected function whereNull(Builder $query, $where): string
    {
        // SOQL compares nulls using = null
        return $this->wrap($where['column']) . ' = null';
    }

    private function whereBoolean(array $where): string
    {
        return $this->wrap($where['column']) . ' = ?';
    }

    protected function whereLiteral(Builder $query, array $where): string
    {
        return "{$this->wrap($where['column'])} {$where['operator']} {$where['value']}";
    }

    /**
     * Check if the $string is a SOSQL String Literal
     * List taken from: https://developer.salesforce.com/docs/atlas.en-us.soql_sosl.meta/soql_sosl/sforce_api_calls_soql_select_dateformats.htm
     */
    protected function checkStringLiteral(string $string): bool
    {
        // some literals use ':' in them, removing before checking
        if (Str::contains($string, ':')) {
            $string = explode(':', $string)[0];
        }
        // check against the array of literals
        return in_array($string, [
            'YESTERDAY',
            'TODAY',
            'TOMORROW',
            'LAST_WEEK',
            'THIS_WEEK',
            'NEXT_WEEK',
            'LAST_MONTH',
            'THIS_MONTH',
            'NEXT_MONTH',
            'LAST_90_DAYS',
            'NEXT_90_DAYS',
            'LAST_N_DAYS',
            'NEXT_N_DAYS',
            'NEXT_N_WEEKS',
            'LAST_N_WEEKS',
            'NEXT_N_MONTHS',
            'LAST_N_MONTHS',
            'THIS_QUARTER',
            'LAST_QUARTER',
            'NEXT_QUARTER',
            'NEXT_N_QUARTERS',
            'LAST_N_QUARTERS',
            'THIS_YEAR',
            'LAST_YEAR',
            'NEXT_YEAR',
            'NEXT_N_YEARS',
            'LAST_N_YEARS',
            'THIS_FISCAL_QUARTER',
            'LAST_FISCAL_QUARTER',
            'NEXT_FISCAL_QUARTER',
            'NEXT_N_FISCAL_QUARTERS',
            'LAST_N_FISCAL_QUARTERS',
            'THIS_FISCAL_YEAR',
            'LAST_FISCAL_YEAR',
            'NEXT_FISCAL_YEAR',
            'NEXT_N_FISCAL_YEARS',
            'LAST_N_FISCAL_YEARS',
        ]);
    }

    protected function compileLock(Builder $query, $value): string
    {
        return 'FOR UPDATE';
    }

    public function getDateFormat(): string
    {
        return 'Y-m-d\TH:i:s\Z';
    }
}
