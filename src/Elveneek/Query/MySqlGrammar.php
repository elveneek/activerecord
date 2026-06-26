<?php

namespace Elveneek\Query;

final class MySqlGrammar
{
    public function compileSelect(QueryBuilder $query): CompiledQuery
    {
        $bindings = [];
        $select = $query->columns ?: [$query->table . '.*'];
        $selectSql = [];
        foreach ($select as $expression) {
            if ($expression instanceof Expression) {
                $selectSql[] = $expression->sql;
                array_push($bindings, ...$expression->bindings);
            } else {
                $selectSql[] = $this->compileSelectable((string) $expression);
            }
        }

        $sql = 'SELECT ' . ($query->distinctValue ? 'DISTINCT ' : '') . implode(', ', $selectSql)
            . ' FROM ' . self::quoteIdentifier($query->table);
        $dependencies = [$query->table];

        foreach ($query->joins as $join) {
            $type = strtoupper($join['type']);
            if (!in_array($type, ['INNER', 'LEFT', 'RIGHT', 'CROSS'], true)) {
                throw new \InvalidArgumentException("Unsupported join type: {$type}");
            }
            if (($join['subquery'] ?? null) instanceof QueryBuilder) {
                $subquery = $this->compileSelect($join['subquery']);
                $sql .= ' ' . $type . ' JOIN (' . $subquery->sql . ') AS ' . self::quoteIdentifier($join['alias']);
                array_push($bindings, ...$subquery->bindings);
                array_push($dependencies, ...$subquery->dependencies);
            } else {
                self::assertIdentifier($join['table']);
                $sql .= ' ' . $type . ' JOIN ' . self::quoteIdentifier($join['table']);
                if ($join['alias']) {
                    self::assertIdentifier($join['alias']);
                    $sql .= ' AS ' . self::quoteIdentifier($join['alias']);
                }
                $dependencies[] = $join['table'];
            }
            if ($type !== 'CROSS') {
                if ($join['left'] === null || $join['right'] === null) {
                    throw new \InvalidArgumentException('A non-cross join requires both columns.');
                }
                self::assertIdentifier($join['left']);
                self::assertIdentifier($join['right']);
                $operator = strtoupper($join['operator']);
                if (!in_array($operator, ['=', '!=', '<>', '<', '>', '<=', '>='], true)) {
                    throw new \InvalidArgumentException("Unsupported join operator: {$operator}");
                }
                $sql .= ' ON ' . self::quoteIdentifier($join['left']) . ' ' . $operator . ' ' . self::quoteIdentifier($join['right']);
            }
        }

        if ($query->wheres) {
            $sql .= ' WHERE ' . $this->compilePredicates($query->wheres, $bindings);
        }
        if ($query->groups) {
            $parts = [];
            foreach ($query->groups as $group) {
                if ($group instanceof Expression) {
                    $parts[] = $group->sql;
                    array_push($bindings, ...$group->bindings);
                } else {
                    $parts[] = self::quoteIdentifier((string) $group);
                }
            }
            $sql .= ' GROUP BY ' . implode(', ', $parts);
        }
        if ($query->havings) {
            $sql .= ' HAVING ' . $this->compilePredicates($query->havings, $bindings);
        }
        if ($query->orders) {
            $parts = [];
            foreach ($query->orders as $order) {
                if ($order instanceof Expression) {
                    $parts[] = $order->sql;
                    array_push($bindings, ...$order->bindings);
                } else {
                    $parts[] = self::quoteIdentifier($order['column']) . ' ' . strtoupper($order['direction']);
                }
            }
            $sql .= ' ORDER BY ' . implode(', ', $parts);
        }
        if ($query->limitValue !== null) {
            $sql .= ' LIMIT ' . $query->limitValue;
        }
        if ($query->offsetValue !== null) {
            if ($query->limitValue === null) {
                $sql .= ' LIMIT 18446744073709551615';
            }
            $sql .= ' OFFSET ' . $query->offsetValue;
        }
        if ($query->lockMode === 'update') {
            $sql .= ' FOR UPDATE';
        } elseif ($query->lockMode === 'share') {
            $sql .= ' LOCK IN SHARE MODE';
        }

        $dependencies = array_merge(
            $dependencies,
            $this->predicateDependencies($query->wheres),
            $this->predicateDependencies($query->havings),
        );
        return new CompiledQuery($sql, $bindings, array_map([$this, 'bindingType'], $bindings), array_values(array_unique($dependencies)));
    }

    public function compileCount(QueryBuilder $query, bool $ignoreLimit = false): CompiledQuery
    {
        $source = $ignoreLimit ? $query->withoutLimitOffset() : $query;
        $source = $source->withoutOrder();
        $compiled = $this->compileSelect($source);
        return new CompiledQuery(
            'SELECT COUNT(*) AS aggregate FROM (' . $compiled->sql . ') AS `_count_source`',
            $compiled->bindings,
            $compiled->bindingTypes,
            $compiled->dependencies,
        );
    }

    public function compilePredicates(array $predicates, array &$bindings): string
    {
        $parts = [];
        foreach ($predicates as $index => $predicate) {
            $prefix = $index === 0 ? '' : ' ' . strtoupper($predicate['boolean'] ?? 'and') . ' ';
            $parts[] = $prefix . $this->compilePredicate($predicate, $bindings);
        }
        return implode('', $parts);
    }

    private function compilePredicate(array $predicate, array &$bindings): string
    {
        return match ($predicate['type']) {
            'comparison' => $this->compileComparison($predicate, $bindings),
            'null' => self::quoteIdentifier($predicate['column']) . ($predicate['not'] ? ' IS NOT NULL' : ' IS NULL'),
            'in' => $this->compileIn($predicate, $bindings),
            'between' => $this->compileBetween($predicate, $bindings),
            'raw' => $this->compileRaw($predicate['sql'], $predicate['bindings'], $bindings),
            'group' => '(' . $this->compilePredicates($predicate['wheres'], $bindings) . ')',
            'exists' => $this->compileExists($predicate, $bindings),
            'column' => self::quoteIdentifier($predicate['left']) . ' ' . strtoupper($predicate['operator']) . ' ' . self::quoteIdentifier($predicate['right']),
            default => throw new \InvalidArgumentException('Unknown predicate type: ' . $predicate['type']),
        };
    }

    private function compileExists(array $predicate, array &$bindings): string
    {
        $compiled = $this->compileSelect($predicate['query']);
        array_push($bindings, ...$compiled->bindings);
        return ($predicate['not'] ? 'NOT ' : '') . 'EXISTS (' . $compiled->sql . ')';
    }
    private function compileComparison(array $predicate, array &$bindings): string
    {
        $bindings[] = $this->normalizeValue($predicate['value']);
        return self::quoteIdentifier($predicate['column']) . ' ' . $predicate['operator'] . ' ?';
    }

    private function compileIn(array $predicate, array &$bindings): string
    {
        if ($predicate['values'] instanceof QueryBuilder) {
            $subquery = $this->compileSelect($predicate['values']);
            array_push($bindings, ...$subquery->bindings);
            return self::quoteIdentifier($predicate['column']) . ($predicate['not'] ? ' NOT IN (' : ' IN (') . $subquery->sql . ')';
        }
        $values = $predicate['values'];
        if ($values === []) {
            return $predicate['not'] ? '1 = 1' : '0 = 1';
        }
        foreach ($values as $value) {
            $bindings[] = $this->normalizeValue($value);
        }
        return self::quoteIdentifier($predicate['column']) . ($predicate['not'] ? ' NOT IN (' : ' IN (')
            . implode(', ', array_fill(0, count($values), '?')) . ')';
    }

    private function compileBetween(array $predicate, array &$bindings): string
    {
        $bindings[] = $this->normalizeValue($predicate['range'][0]);
        $bindings[] = $this->normalizeValue($predicate['range'][1]);
        return self::quoteIdentifier($predicate['column']) . ($predicate['not'] ? ' NOT BETWEEN ? AND ?' : ' BETWEEN ? AND ?');
    }

    private function compileRaw(string $sql, array $rawBindings, array &$bindings): string
    {
        foreach ($rawBindings as $value) {
            if (is_array($value)) {
                $replacement = $value === [] ? 'NULL' : implode(', ', array_fill(0, count($value), '?'));
                $position = strpos($sql, '?');
                if ($position === false) {
                    throw new \InvalidArgumentException('More bindings than placeholders in raw predicate.');
                }
                $sql = substr_replace($sql, $replacement, $position, 1);
                foreach ($value as $item) {
                    $bindings[] = $this->normalizeValue($item);
                }
            } else {
                $bindings[] = $this->normalizeValue($value);
            }
        }
        return '(' . $sql . ')';
    }

    private function predicateDependencies(array $predicates): array
    {
        $dependencies = [];
        foreach ($predicates as $predicate) {
            if (($predicate['type'] ?? null) === 'group') {
                array_push($dependencies, ...$this->predicateDependencies($predicate['wheres'] ?? []));
                continue;
            }
            $subquery = match ($predicate['type'] ?? null) {
                'in' => $predicate['values'] ?? null,
                'exists' => $predicate['query'] ?? null,
                default => null,
            };
            if ($subquery instanceof QueryBuilder) {
                array_push($dependencies, ...$this->compileSelect($subquery)->dependencies);
            }
        }
        return array_values(array_unique($dependencies));
    }

    private function compileSelectable(string $expression): string
    {
        $expression = trim($expression);
        if (preg_match('/[;#]|--|\/\*/', $expression)) {
            throw new \Elveneek\Exception\InvalidIdentifierException("Unsafe select expression: {$expression}. Use selectRaw() for intentional SQL.");
        }
        if (preg_match('/^(.+?)\s+AS\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $expression, $matches)) {
            $base = trim($matches[1]);
            if (self::isSimpleIdentifier($base)) {
                $compiled = self::quoteIdentifier($base);
            } elseif (preg_match('/^(?:COUNT|SUM|AVG|MIN|MAX)\(\s*(?:\*|[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?)\s*\)$/i', $base)) {
                $compiled = $base;
            } else {
                throw new \Elveneek\Exception\InvalidIdentifierException("Invalid select expression: {$expression}. Use selectRaw() for intentional SQL.");
            }
            return $compiled . ' AS ' . self::quoteIdentifier($matches[2]);
        }
        if (self::isSimpleIdentifier($expression)) {
            return self::quoteIdentifier($expression);
        }
        throw new \Elveneek\Exception\InvalidIdentifierException("Invalid select expression: {$expression}. Use selectRaw() for intentional SQL.");
    }
    public static function assertIdentifier(string $identifier): void
    {
        if (!self::isSimpleIdentifier($identifier)) {
            throw new \Elveneek\Exception\InvalidIdentifierException("Invalid SQL identifier: {$identifier}");
        }
    }

    public static function quoteIdentifier(string $identifier): string
    {
        self::assertIdentifier($identifier);
        return implode('.', array_map(static fn ($part) => $part === '*' ? '*' : '`' . $part . '`', explode('.', $identifier)));
    }

    private static function isSimpleIdentifier(string $identifier): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.(?:[A-Za-z_][A-Za-z0-9_]*|\*))?$/', $identifier);
    }

    private function normalizeValue(mixed $value): mixed
    {
        return defined('SQL_NULL') && $value === constant('SQL_NULL') ? null : $value;
    }

    private function bindingType(mixed $value): int
    {
        return match (true) {
            $value === null => \PDO::PARAM_NULL,
            is_bool($value) => \PDO::PARAM_BOOL,
            is_int($value) => \PDO::PARAM_INT,
            default => \PDO::PARAM_STR,
        };
    }
}
