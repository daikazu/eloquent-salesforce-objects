<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Database;

use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class SOQLGrammar extends Grammar
{
    protected ?SalesforceModel $model = null;

    /** A SOQL date literal: 2025-01-31 */
    public const string DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    /** A SOQL datetime literal: 2025-01-31T10:00:00Z, 2025-01-31T10:00:00.000+0000 */
    public const string DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,3})?(Z|[+-]\d{2}:?\d{2})$/';

    /** @var array<string, array<string, string>> */
    private array $fieldTypes = [];

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

        $operator = str_replace('?', '??', (string) $where['operator']);

        return $this->wrap($where['column']) . ' ' . $operator . ' ' . $this->typedParameter($query, $where['column'], $where['value']);
    }

    /**
     * A placeholder for a value compared with a column. Date and datetime fields get a typed
     * placeholder ("?:date" / "?:datetime"), which SOQLConnection::substituteBindings() fills
     * with an unquoted literal in that field's format. Anything else is a normal parameter.
     */
    protected function typedParameter(Builder $query, mixed $column, mixed $value): string
    {
        $type = $this->temporalTypeFor($query, $column, $value);

        return $type === null ? $this->parameter($value) : "?:{$type}";
    }

    /**
     * "date" or "datetime" when $value is a date for that kind of field, otherwise null.
     *
     * Only values that can be dates trigger a describe lookup, and strings must match a
     * date/datetime format exactly, because they're sent unquoted.
     */
    private function temporalTypeFor(Builder $query, mixed $column, mixed $value): ?string
    {
        $isDateString = is_string($value) && preg_match(self::DATE_PATTERN, $value) === 1;
        $isDatetimeString = is_string($value) && preg_match(self::DATETIME_PATTERN, $value) === 1;

        if (! ($value instanceof DateTimeInterface || $isDateString || $isDatetimeString)
            || ! is_string($column) || str_contains($column, '.') || ! is_string($query->from)) {
            return null;
        }

        return match ($this->fieldTypes($query->from)[$column] ?? null) {
            'date'     => $value instanceof DateTimeInterface || $isDateString ? 'date' : null,
            'datetime' => 'datetime',
            default    => null,
        };
    }

    /**
     * Field name => describe type for an object, looked up once per grammar (one per builder).
     * A failed describe means no types, so values fall back to normal parameters.
     *
     * @return array<string, string>
     */
    private function fieldTypes(string $object): array
    {
        if (! array_key_exists($object, $this->fieldTypes)) {
            $types = [];

            try {
                if ($this->connection instanceof SOQLConnection) {
                    foreach ($this->connection->getAdapter()->describe($object)['fields'] ?? [] as $field) {
                        if (isset($field['name'], $field['type'])) {
                            $types[$field['name']] = $field['type'];
                        }
                    }
                }
            } catch (Throwable) {
                $types = [];
            }

            $this->fieldTypes[$object] = $types;
        }

        return $this->fieldTypes[$object];
    }

    /**
     * Compare the date part of a field. A datetime field needs DAY_ONLY() (the day in UTC);
     * a date field compares directly. Only a strict YYYY-MM-DD value goes in unquoted.
     */
    protected function whereDate(Builder $query, $where): string
    {
        $column = $this->wrap($where['column']);

        if (is_string($where['column']) && is_string($query->from)
            && ($this->fieldTypes($query->from)[$where['column']] ?? null) === 'datetime') {
            $column = "DAY_ONLY({$column})";
        }

        $value = is_string($where['value']) && preg_match(self::DATE_PATTERN, $where['value']) === 1
            ? '?:date'
            : $this->parameter($where['value']);

        return "{$column} {$where['operator']} {$value}";
    }

    protected function whereYear(Builder $query, $where): string
    {
        return $this->datePartWhere('CALENDAR_YEAR', $where);
    }

    protected function whereMonth(Builder $query, $where): string
    {
        return $this->datePartWhere('CALENDAR_MONTH', $where);
    }

    protected function whereDay(Builder $query, $where): string
    {
        return $this->datePartWhere('DAY_IN_MONTH', $where);
    }

    /**
     * SOQL date functions take an unquoted number; anything else is quoted (and rejected by Salesforce).
     */
    private function datePartWhere(string $function, array $where): string
    {
        $value = is_int($where['value']) || (is_string($where['value']) && ctype_digit($where['value']))
            ? '?'
            : $this->parameter($where['value']);

        return "{$function}({$this->wrap($where['column'])}) {$where['operator']} {$value}";
    }

    protected function compileLimit(Builder $query, $limit): string
    {
        return 'limit ' . (int) $limit;
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
            return $this->wrap($where['column']) . ' in (' . $this->typedParameters($query, $where) . ')';
        }

        return 'Id = null';
    }

    /**
     * An empty NOT IN list matches everything; "Id != null" is always true. (SQL's "1 = 1" isn't valid SOQL.)
     */
    protected function whereNotIn(Builder $query, $where): string
    {
        if (! empty($where['values'])) {
            return $this->wrap($where['column']) . ' not in (' . $this->typedParameters($query, $where) . ')';
        }

        return 'Id != null';
    }

    private function typedParameters(Builder $query, array $where): string
    {
        return implode(', ', array_map(
            fn (mixed $value): string => $this->typedParameter($query, $where['column'], $value),
            array_values($where['values'])
        ));
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

        $min = $this->typedParameter($query, $where['column'], $values[0]);
        $max = $this->typedParameter($query, $where['column'], $values[count($values) - 1]);

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
