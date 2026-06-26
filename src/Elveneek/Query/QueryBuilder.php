<?php

namespace Elveneek\Query;

use Elveneek\DB;

final class QueryBuilder
{
    public function __construct(
        public readonly string $table,
        public readonly ?string $modelClass = null,
        public readonly string $primaryKey = 'id',
        public readonly array $columns = [],
        public readonly array $wheres = [],
        public readonly array $joins = [],
        public readonly array $groups = [],
        public readonly array $havings = [],
        public readonly array $orders = [],
        public readonly ?int $limitValue = null,
        public readonly ?int $offsetValue = null,
        public readonly bool $distinctValue = false,
        public readonly array $eagerLoads = [],
        public readonly ?array $lookupIds = null,
        public readonly bool $identityMapEnabled = true,
        public readonly ?int $rememberSeconds = null,
        public readonly ?string $rememberKey = null,
        public readonly ?string $lockMode = null,
    ) {
    }

    private function changed(array $changes): self
    {
        $values = [
            'table' => $this->table,
            'modelClass' => $this->modelClass,
            'primaryKey' => $this->primaryKey,
            'columns' => $this->columns,
            'wheres' => $this->wheres,
            'joins' => $this->joins,
            'groups' => $this->groups,
            'havings' => $this->havings,
            'orders' => $this->orders,
            'limitValue' => $this->limitValue,
            'offsetValue' => $this->offsetValue,
            'distinctValue' => $this->distinctValue,
            'eagerLoads' => $this->eagerLoads,
            'lookupIds' => $this->lookupIds,
            'identityMapEnabled' => $this->identityMapEnabled,
            'rememberSeconds' => $this->rememberSeconds,
            'rememberKey' => $this->rememberKey,
            'lockMode' => $this->lockMode,
        ];

        return new self(...array_replace($values, $changes));
    }

    public function select(string|array ...$columns): self
    {
        return $this->changed(['columns' => $this->normalizeList($columns)]);
    }

    public function addSelect(string|array ...$columns): self
    {
        return $this->changed(['columns' => array_merge($this->columns, $this->normalizeList($columns))]);
    }

    public function selectRaw(string $sql, array $bindings = []): self
    {
        return $this->changed(['columns' => array_merge($this->columns, [new Expression($sql, $bindings)])]);
    }

    public function distinct(bool $distinct = true): self
    {
        return $this->changed(['distinctValue' => $distinct]);
    }

    public function where(mixed ...$arguments): self
    {
        return $this->addWhere('and', $arguments);
    }

    public function orWhere(mixed ...$arguments): self
    {
        return $this->addWhere('or', $arguments);
    }

    public function whereGroup(callable $callback, string $boolean = 'and'): self
    {
        $nested = new self($this->table, $this->modelClass, $this->primaryKey);
        $proxy = new MutableQueryProxy($nested);
        $returned = $callback($proxy);
        $result = $returned instanceof self ? $returned : $proxy->query();
        if ($result->wheres === []) {
            return $this;
        }

        return $this->appendWhere(['type' => 'group', 'boolean' => strtolower($boolean), 'wheres' => $result->wheres]);
    }
    public function orWhereGroup(callable $callback): self
    {
        return $this->whereGroup($callback, 'or');
    }

    public function whereIn(string $column, iterable|self $values): self
    {
        return $this->listPredicate($column, $values, false, 'and');
    }

    public function orWhereIn(string $column, iterable|self $values): self
    {
        return $this->listPredicate($column, $values, false, 'or');
    }

    public function whereNotIn(string $column, iterable|self $values): self
    {
        return $this->listPredicate($column, $values, true, 'and');
    }

    public function orWhereNotIn(string $column, iterable|self $values): self
    {
        return $this->listPredicate($column, $values, true, 'or');
    }

    public function whereNull(string $column): self
    {
        return $this->appendWhere(['type' => 'null', 'boolean' => 'and', 'column' => $column, 'not' => false]);
    }

    public function orWhereNull(string $column): self
    {
        return $this->appendWhere(['type' => 'null', 'boolean' => 'or', 'column' => $column, 'not' => false]);
    }

    public function whereNotNull(string $column): self
    {
        return $this->appendWhere(['type' => 'null', 'boolean' => 'and', 'column' => $column, 'not' => true]);
    }

    public function orWhereNotNull(string $column): self
    {
        return $this->appendWhere(['type' => 'null', 'boolean' => 'or', 'column' => $column, 'not' => true]);
    }

    public function whereBetween(string $column, array $range): self
    {
        return $this->betweenPredicate($column, $range, false, 'and');
    }

    public function whereNotBetween(string $column, array $range): self
    {
        return $this->betweenPredicate($column, $range, true, 'and');
    }

    public function whereLike(string $column, mixed $pattern): self
    {
        return $this->where($column, 'LIKE', $pattern);
    }

    public function orWhereLike(string $column, mixed $pattern): self
    {
        return $this->orWhere($column, 'LIKE', $pattern);
    }

    public function whereExists(self $query): self
    {
        return $this->appendWhere(['type' => 'exists', 'boolean' => 'and', 'query' => $query, 'not' => false]);
    }

    public function whereNotExists(self $query): self
    {
        return $this->appendWhere(['type' => 'exists', 'boolean' => 'and', 'query' => $query, 'not' => true]);
    }

    public function whereColumn(string $left, string $operator, string $right): self
    {
        MySqlGrammar::assertIdentifier($left);
        MySqlGrammar::assertIdentifier($right);
        $operator = strtoupper(trim($operator));
        if (!in_array($operator, ['=', '!=', '<>', '<', '>', '<=', '>='], true)) {
            throw new \Elveneek\Exception\UnsupportedOperatorException("Unsupported column comparison operator: {$operator}");
        }
        return $this->appendWhere(['type' => 'column', 'boolean' => 'and', 'left' => $left, 'operator' => $operator, 'right' => $right]);
    }
    public function whereRaw(string $sql, mixed ...$bindings): self
    {
        return $this->rawPredicate($sql, $bindings, 'and');
    }

    public function orWhereRaw(string $sql, mixed ...$bindings): self
    {
        return $this->rawPredicate($sql, $bindings, 'or');
    }

    public function join(string $table, ?string $left = null, string $operator = '=', ?string $right = null, string $type = 'inner', ?string $alias = null): self
    {
        $joins = $this->joins;
        $joins[] = compact('table', 'left', 'operator', 'right', 'type', 'alias');
        return $this->changed(['joins' => $joins]);
    }

    public function leftJoin(string $table, string $left, string $operator, string $right): self
    {
        return $this->join($table, $left, $operator, $right, 'left');
    }

    public function rightJoin(string $table, string $left, string $operator, string $right): self
    {
        return $this->join($table, $left, $operator, $right, 'right');
    }

    public function crossJoin(string $table): self
    {
        return $this->join($table, null, '=', null, 'cross');
    }

    public function joinSub(self $query, string $alias, string $left, string $operator, string $right, string $type = 'inner'): self
    {
        MySqlGrammar::assertIdentifier($alias);
        $joins = $this->joins;
        $joins[] = ['table' => $alias, 'left' => $left, 'operator' => $operator, 'right' => $right, 'type' => $type, 'alias' => $alias, 'subquery' => $query];
        return $this->changed(['joins' => $joins]);
    }

    public function leftJoinSub(self $query, string $alias, string $left, string $operator, string $right): self
    {
        return $this->joinSub($query, $alias, $left, $operator, $right, 'left');
    }
    public function groupBy(string|array ...$columns): self
    {
        return $this->changed(['groups' => array_merge($this->groups, $this->normalizeList($columns))]);
    }

    public function groupByRaw(string $sql, array $bindings = []): self
    {
        return $this->changed(['groups' => array_merge($this->groups, [new Expression($sql, $bindings)])]);
    }

    public function having(mixed ...$arguments): self
    {
        $temporary = (new self($this->table))->addWhere('and', $arguments);
        return $this->changed(['havings' => array_merge($this->havings, $temporary->wheres)]);
    }

    public function havingRaw(string $sql, mixed ...$bindings): self
    {
        $temporary = (new self($this->table))->rawPredicate($sql, $bindings, 'and');
        return $this->changed(['havings' => array_merge($this->havings, $temporary->wheres)]);
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        if (trim($column) === '') {
            return $this->withoutOrder();
        }
        $direction = strtolower(trim($direction));
        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException("Invalid order direction: {$direction}");
        }
        MySqlGrammar::assertIdentifier($column);
        return $this->changed(['orders' => array_merge($this->orders, [['column' => $column, 'direction' => $direction]])]);
    }

    public function orderByRaw(string $sql, array $bindings = []): self
    {
        return $this->changed(['orders' => array_merge($this->orders, [new Expression($sql, $bindings)])]);
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException('Limit cannot be negative.');
        }
        return $this->changed(['limitValue' => $limit]);
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('Offset cannot be negative.');
        }
        return $this->changed(['offsetValue' => $offset]);
    }

    public function with(string ...$relations): self
    {
        $relations = $this->normalizeList($relations);
        return $this->changed(['eagerLoads' => array_values(array_unique(array_merge($this->eagerLoads, $relations)))]);
    }

    public function whereKey(int|string $id): self
    {
        return $this->where($this->primaryKey, '=', $id)->limit(1)->changed(['lookupIds' => [$id]]);
    }

    public function whereKeys(array $ids): self
    {
        return $this->whereIn($this->primaryKey, $ids)->changed(['lookupIds' => array_values($ids)]);
    }

    public function withoutCache(): self
    {
        return $this->changed(['identityMapEnabled' => false, 'rememberSeconds' => null, 'rememberKey' => null]);
    }

    public function withoutIdentityMap(): self
    {
        return $this->changed(['identityMapEnabled' => false]);
    }

    public function remember(int $seconds, ?string $key = null): self
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException('Cache lifetime must be positive.');
        }
        return $this->changed(['rememberSeconds' => $seconds, 'rememberKey' => $key]);
    }

    public function rememberForever(?string $key = null): self
    {
        return $this->changed(['rememberSeconds' => PHP_INT_MAX, 'rememberKey' => $key]);
    }

    public function lockForUpdate(): self
    {
        return $this->changed(['lockMode' => 'update']);
    }

    public function sharedLock(): self
    {
        return $this->changed(['lockMode' => 'share']);
    }

    public function withoutLimitOffset(): self
    {
        return $this->changed(['limitValue' => null, 'offsetValue' => null]);
    }

    public function withoutOrder(): self
    {
        return $this->changed(['orders' => []]);
    }

    public function toSql(): string
    {
        return (new MySqlGrammar())->compileSelect($this)->sql;
    }

    public function bindings(): array
    {
        return (new MySqlGrammar())->compileSelect($this)->bindings;
    }

    public function fingerprint(): string
    {
        $compiled = (new MySqlGrammar())->compileSelect($this);
        return hash('sha256', serialize([$compiled->sql, $compiled->bindings, $this->modelClass]));
    }

    public function dependencies(): array
    {
        return (new MySqlGrammar())->compileSelect($this)->dependencies;
    }

    public function count(): int
    {
        $compiled = (new MySqlGrammar())->compileCount($this);
        return (int) \Elveneek\DB::execute($compiled->sql, $compiled->bindings)->fetchColumn();
    }

    public function exists(): bool
    {
        return $this->limit(1)->count() > 0;
    }

    public function sum(string $column): int|float|null
    {
        return $this->aggregateValue('SUM', $column);
    }

    public function avg(string $column): int|float|null
    {
        return $this->aggregateValue('AVG', $column);
    }

    public function min(string $column): mixed
    {
        return $this->aggregateValue('MIN', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregateValue('MAX', $column);
    }

    private function aggregateValue(string $function, string $column): mixed
    {
        MySqlGrammar::assertIdentifier($column);
        $query = $this->withoutOrder()->withoutLimitOffset()
            ->selectRaw($function . '(' . MySqlGrammar::quoteIdentifier($column) . ') AS aggregate');
        return $query->firstRow()?->aggregate;
    }
    public function rows(): array
    {
        return DB::runQuery($this);
    }

    public function firstRow(): ?object
    {
        $rows = $this->limit(1)->rows();
        return $rows[0] ?? null;
    }

    public function value(string $column): mixed
    {
        $row = $this->select($column)->limit(1)->firstRow();
        return $row?->{$column};
    }

    public function column(string $column): array
    {
        return array_map(static fn ($row) => $row->{$column} ?? null, $this->select($column)->rows());
    }

    private function addWhere(string $boolean, array $arguments): self
    {
        $count = count($arguments);
        if ($count === 1 && is_array($arguments[0])) {
            $query = $this;
            foreach ($arguments[0] as $column => $value) {
                $query = $query->addWhere($boolean, [$column, $value]);
                $boolean = 'and';
            }
            return $query;
        }
        if ($count === 1 && is_callable($arguments[0])) {
            return $this->whereGroup($arguments[0], $boolean);
        }
        if ($count === 2 && is_string($arguments[0]) && self::isIdentifier($arguments[0])) {
            return $this->comparison($arguments[0], '=', $arguments[1], $boolean);
        }
        if ($count === 3 && is_string($arguments[0]) && self::isIdentifier($arguments[0]) && self::isOperator($arguments[1])) {
            return $this->comparison($arguments[0], (string) $arguments[1], $arguments[2], $boolean);
        }
        if ($count >= 1 && is_string($arguments[0])) {
            return $this->rawPredicate(array_shift($arguments), $arguments, $boolean);
        }
        throw new \InvalidArgumentException('Unsupported where() arguments.');
    }

    private function comparison(string $column, string $operator, mixed $value, string $boolean): self
    {
        MySqlGrammar::assertIdentifier($column);
        $operator = strtoupper(trim($operator));
        if (!self::isOperator($operator)) {
            throw new \Elveneek\Exception\UnsupportedOperatorException("Unsupported SQL operator: {$operator}");
        }
        if ($value === null || (defined('SQL_NULL') && $value === constant('SQL_NULL'))) {
            if (in_array($operator, ['=', 'IS'], true)) {
                return $this->appendWhere(['type' => 'null', 'boolean' => $boolean, 'column' => $column, 'not' => false]);
            }
            if (in_array($operator, ['!=', '<>', 'IS NOT'], true)) {
                return $this->appendWhere(['type' => 'null', 'boolean' => $boolean, 'column' => $column, 'not' => true]);
            }
        }
        return $this->appendWhere(compact('column', 'operator', 'value', 'boolean') + ['type' => 'comparison']);
    }

    private function listPredicate(string $column, iterable|self $values, bool $not, string $boolean): self
    {
        MySqlGrammar::assertIdentifier($column);
        if (!$values instanceof self) {
            $values = is_array($values) ? array_values($values) : iterator_to_array($values, false);
        }
        return $this->appendWhere(compact('column', 'values', 'not', 'boolean') + ['type' => 'in']);
    }

    private function betweenPredicate(string $column, array $range, bool $not, string $boolean): self
    {
        if (count($range) !== 2) {
            throw new \InvalidArgumentException('whereBetween() expects exactly two values.');
        }
        MySqlGrammar::assertIdentifier($column);
        return $this->appendWhere(compact('column', 'range', 'not', 'boolean') + ['type' => 'between']);
    }

    private function rawPredicate(string $sql, array $bindings, string $boolean): self
    {
        return $this->appendWhere(compact('sql', 'bindings', 'boolean') + ['type' => 'raw']);
    }

    private function appendWhere(array $where): self
    {
        return $this->changed(['wheres' => array_merge($this->wheres, [$where])]);
    }

    private function normalizeList(array $values): array
    {
        $result = [];
        array_walk_recursive($values, static function ($value) use (&$result): void {
            if (is_string($value) && str_contains($value, ',')) {
                foreach (explode(',', $value) as $part) {
                    if (trim($part) !== '') {
                        $result[] = trim($part);
                    }
                }
            } elseif ($value !== '') {
                $result[] = $value;
            }
        });
        return $result;
    }

    private static function isIdentifier(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_*][A-Za-z0-9_]*)?$/', $value);
    }

    private static function isOperator(mixed $value): bool
    {
        return is_string($value) && in_array(strtoupper(trim($value)), [
            '=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IS', 'IS NOT',
        ], true);
    }
}
