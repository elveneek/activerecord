<?php

namespace Elveneek\Concerns;

use Elveneek\Metadata\Inflector;
use Elveneek\Query\MySqlGrammar;

trait RelationQueryApi
{
    protected function whereHas(string $relation, ?callable $callback = null): static
    {
        return $this->relationExists($relation, $callback, false);
    }

    protected function whereDoesntHave(string $relation, ?callable $callback = null): static
    {
        return $this->relationExists($relation, $callback, true);
    }

    protected function has(string $relation): static
    {
        return $this->whereHas($relation);
    }

    protected function doesntHave(string $relation): static
    {
        return $this->whereDoesntHave($relation);
    }

    private function relationExists(string $relation, ?callable $callback, bool $negated): static
    {
        $targetTable = Inflector::plural(Inflector::singular($relation));
        $target = $this->modelForTable($targetTable);
        $foreign = Inflector::singular($relation) . '_id';
        if (isset($this->metadata->columns()[$foreign])) {
            $target->whereRaw(
                MySqlGrammar::quoteIdentifier($targetTable . '.id') . ' = ' . MySqlGrammar::quoteIdentifier($this->tableName() . '.' . $foreign),
            );
        } else {
            $sourceForeign = Inflector::singular($this->tableName()) . '_id';
            if (!isset($target->metadata->columns()[$sourceForeign])) {
                throw new \RuntimeException("whereHas() currently requires a belongs-to or has-many relation.");
            }
            $target->whereRaw(
                MySqlGrammar::quoteIdentifier($targetTable . '.' . $sourceForeign) . ' = ' . MySqlGrammar::quoteIdentifier($this->tableName() . '.' . $this->primaryKeyName()),
            );
        }
        $callback && $callback($target);
        $compiled = (new MySqlGrammar())->compileSelect($target->toQuery()->selectRaw('1'));
        $sql = ($negated ? 'NOT ' : '') . 'EXISTS (' . $compiled->sql . ')';
        return $this->changeQuery($this->query->whereRaw($sql, ...$compiled->bindings));
    }
}