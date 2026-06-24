<?php

namespace Elveneek\Relations;

use Elveneek\ActiveRecord;
use Elveneek\DB;
use Elveneek\Metadata\Inflector;
use Elveneek\Query\MySqlGrammar;

final class RelationManager
{
    public function __construct(private ActiveRecord $owner, private string $name)
    {
    }

    public function get(): ?ActiveRecord
    {
        return $this->owner->related($this->name);
    }

    public function associate(?ActiveRecord $record): ActiveRecord
    {
        $this->owner->{$this->name . '_id'} = $record?->id;
        return $this->owner;
    }

    public function dissociate(): ActiveRecord
    {
        return $this->associate(null);
    }

    public function attach(int|array $ids, array $attributes = []): self
    {
        foreach (is_array($ids) ? $ids : [$ids] as $id) {
            $this->writePivot((int) $id, $attributes, false);
        }
        return $this;
    }

    public function detach(int|array|null $ids = null): self
    {
        $related = $this->owner->related($this->name);
        if (!$related) {
            return $this;
        }
        $ownerTable = $this->owner->table;
        $targetTable = $related->table;
        $pivot = min($ownerTable, $targetTable) . '_to_' . max($ownerTable, $targetTable);
        $ownerKey = Inflector::singular($ownerTable) . '_id';
        $targetKey = Inflector::singular($targetTable) . '_id';
        $sql = 'DELETE FROM ' . MySqlGrammar::quoteIdentifier($pivot) . ' WHERE ' . MySqlGrammar::quoteIdentifier($ownerKey) . ' = ?';
        $bindings = [$this->owner->id];
        if ($ids !== null) {
            $ids = is_array($ids) ? $ids : [$ids];
            $sql .= ' AND ' . MySqlGrammar::quoteIdentifier($targetKey) . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($bindings, ...$ids);
        }
        DB::execute($sql, $bindings);
        return $this;
    }

    public function sync(array $ids): self
    {
        $this->detach();
        return $this->attach($ids);
    }

    private function writePivot(int $id, array $attributes, bool $replace): void
    {
        $related = $this->owner->related($this->name);
        if (!$related) {
            throw new \RuntimeException("Cannot resolve relation {$this->name}.");
        }
        $ownerTable = $this->owner->table;
        $targetTable = $related->table;
        $pivot = min($ownerTable, $targetTable) . '_to_' . max($ownerTable, $targetTable);
        $values = array_merge([
            Inflector::singular($ownerTable) . '_id' => $this->owner->id,
            Inflector::singular($targetTable) . '_id' => $id,
        ], $attributes);
        $fields = array_keys($values);
        $sql = 'INSERT INTO ' . MySqlGrammar::quoteIdentifier($pivot) . ' ('
            . implode(',', array_map([MySqlGrammar::class, 'quoteIdentifier'], $fields)) . ') VALUES ('
            . implode(',', array_fill(0, count($fields), '?')) . ')';
        DB::execute($sql, array_values($values));
    }
}
