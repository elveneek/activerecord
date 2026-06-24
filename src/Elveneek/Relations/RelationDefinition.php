<?php

namespace Elveneek\Relations;

use Elveneek\ActiveRecord;
use Elveneek\DB;
use Elveneek\Metadata\Inflector;
use Elveneek\Query\MySqlGrammar;

final class RelationDefinition
{
    public function __construct(
        private ActiveRecord $owner,
        private string $type,
        private string $targetClass,
        private ?string $foreignKey = null,
        private ?string $pivotTable = null,
        private string $ownerKey = 'id',
        private string $targetKey = 'id',
    ) {
    }

    public function get(): ActiveRecord
    {
        $target = new $this->targetClass();
        $targetTable = $target->table;
        $ownerTable = $this->owner->table;
        return match ($this->type) {
            'belongsTo' => $this->belongsToResult($targetTable),
            'hasMany' => $this->targetClass::where(
                $this->foreignKey ?? Inflector::singular($ownerTable) . '_id',
                $this->owner->{$this->ownerKey},
            ),
            'belongsToMany' => $this->targetClass::join(
                $this->pivot(),
                $this->pivot() . '.' . Inflector::singular($targetTable) . '_id',
                '=',
                $targetTable . '.' . $this->targetKey,
            )->where(
                $this->pivot() . '.' . Inflector::singular($ownerTable) . '_id',
                $this->owner->{$this->ownerKey},
            )->distinct(),
            default => throw new \LogicException("Unknown relation type {$this->type}."),
        };
    }

    public function associate(?ActiveRecord $record): ActiveRecord
    {
        if ($this->type !== 'belongsTo') {
            throw new \LogicException('associate() is available only for belongsTo relations.');
        }
        $target = new $this->targetClass();
        $this->owner->{$this->foreignKey ?? Inflector::singular($target->table) . '_id'} = $record?->{$this->targetKey};
        return $this->owner;
    }

    public function dissociate(): ActiveRecord
    {
        return $this->associate(null);
    }

    public function attach(int|array $ids, array $attributes = []): self
    {
        if ($this->type !== 'belongsToMany') {
            throw new \LogicException('attach() is available only for belongsToMany relations.');
        }
        $target = new $this->targetClass();
        $ownerColumn = Inflector::singular($this->owner->table) . '_id';
        $targetColumn = Inflector::singular($target->table) . '_id';
        $ids = is_array($ids) ? array_values($ids) : [$ids];
        foreach ($ids as $id) {
            $values = array_merge([$ownerColumn => $this->owner->{$this->ownerKey}, $targetColumn => $id], $attributes);
            $fields = array_keys($values);
            DB::execute(
                'INSERT INTO ' . MySqlGrammar::quoteIdentifier($this->pivot()) . ' ('
                    . implode(', ', array_map([MySqlGrammar::class, 'quoteIdentifier'], $fields)) . ') VALUES ('
                    . implode(', ', array_fill(0, count($fields), '?')) . ')',
                array_values($values),
            );
        }
        if ($ids !== []) {
            ActiveRecord::invalidateTableCache($this->pivot());
        }
        return $this;
    }

    public function detach(int|array|null $ids = null): self
    {
        if ($ids === []) {
            return $this;
        }
        $target = new $this->targetClass();
        $ownerColumn = Inflector::singular($this->owner->table) . '_id';
        $targetColumn = Inflector::singular($target->table) . '_id';
        $sql = 'DELETE FROM ' . MySqlGrammar::quoteIdentifier($this->pivot()) . ' WHERE '
            . MySqlGrammar::quoteIdentifier($ownerColumn) . ' = ?';
        $bindings = [$this->owner->{$this->ownerKey}];
        if ($ids !== null) {
            $ids = is_array($ids) ? array_values($ids) : [$ids];
            $sql .= ' AND ' . MySqlGrammar::quoteIdentifier($targetColumn) . ' IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
            array_push($bindings, ...$ids);
        }
        DB::execute($sql, $bindings);
        ActiveRecord::invalidateTableCache($this->pivot());
        return $this;
    }

    public function sync(array $ids): self
    {
        return DB::transaction(function () use ($ids): self {
            $this->detach();
            return $this->attach($ids);
        });
    }
    private function belongsToResult(string $targetTable): ActiveRecord
    {
        $id = $this->owner->{$this->foreignKey ?? Inflector::singular($targetTable) . '_id'};
        return $id === null ? $this->targetClass::whereRaw('0 = 1') : $this->targetClass::find($id);
    }
    private function pivot(): string
    {
        if ($this->pivotTable) {
            return $this->pivotTable;
        }
        $target = new $this->targetClass();
        return min($this->owner->table, $target->table) . '_to_' . max($this->owner->table, $target->table);
    }
}
