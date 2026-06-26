<?php

namespace Elveneek\Concerns;

use Elveneek\ActiveRecord;
use Elveneek\Metadata\Inflector;
use Elveneek\Records\RecordState;

trait RelationsApi
{
    public function related(string $name): ?ActiveRecord
    {
        $legacyTraversal = str_starts_with($name, '_');
        $name = ltrim($name, '_');
        $states = $this->boundRow && $this->boundContext
            ? $this->boundContext->states()
            : ($this->boundRow ? [$this->boundRow->state] : $this->ensureCollection()->states());

        if ($this->boundRow && array_key_exists($name, $this->boundRow->state->relationCache)) {
            return $this->boundRow->state->relationCache[$name];
        }

        $sourceIds = array_values(array_filter(
            array_map(static fn (RecordState $state) => $state->key(), $states),
            static fn ($id) => $id !== null,
        ));
        $targetTable = Inflector::plural(Inflector::singular($name));
        $targetClass = $this->modelClassForTable($targetTable);
        if (!$targetClass) {
            return null;
        }

        /** @var ActiveRecord $target */
        $target = new $targetClass();
        $foreignKey = Inflector::singular($name) . '_id';
        $sourceHasForeign = isset($this->metadata->columns()[$foreignKey])
            && ($legacyTraversal || $name === Inflector::singular($name));

        if ($sourceHasForeign) {
            $belongsIds = [];
            foreach ($states as $state) {
                if (array_key_exists($foreignKey, $state->attributes) && $state->attributes[$foreignKey] !== null) {
                    $belongsIds[] = $state->attributes[$foreignKey];
                }
            }

            if ($belongsIds === []) {
                return $target->changeQuery($target->query->whereRaw('0 = 1'));
            }

            $result = $targetClass::findMany(array_values(array_unique($belongsIds)));
            if (!$this->boundRow) {
                return $result;
            }

            $result->load();
            foreach ($states as $sourceState) {
                $foreignId = $sourceState->attributes[$foreignKey] ?? null;
                $sourceState->relationCache[$name] = $foreignId === null ? null : $result->by_id($foreignId);
            }
            return $this->boundRow->state->relationCache[$name];
        }

        $sourceForeign = Inflector::singular($this->tableName()) . '_id';
        if (isset($target->metadata->columns()[$sourceForeign])) {
            $result = $target->changeQuery($target->query->whereIn($sourceForeign, $sourceIds));
            if ($this->boundRow) {
                $this->boundRow->state->relationCache[$name] = $result;
            }
            return $result;
        }

        return null;
    }

    public function allLinked(string $relation): ?ActiveRecord
    {
        $ids = [];
        $current = $this;
        for ($i = 0; $i < 100 && $current && !$current->isEmpty(); $i++) {
            $ids = array_merge($ids, $current->pluck($current->primaryKeyName()));
            $current = $current->related('_' . $relation);
        }
        $class = $this->modelClassForTable(Inflector::plural(Inflector::singular($relation)));
        return $class ? $class::findMany(array_values(array_unique($ids))) : null;
    }

    public function plus(int|array|ActiveRecord $elements = []): static
    {
        $ids = $this->pluck($this->primaryKeyName());
        if ($elements instanceof ActiveRecord) {
            $elements = $elements->pluck($elements->primaryKeyName());
        }
        foreach (is_array($elements) ? $elements : [$elements] as $element) {
            $ids[] = is_array($element) ? ($element[$this->primaryKeyName()] ?? null) : $element;
        }
        return static::findMany(array_values(array_unique(array_filter($ids, static fn ($id) => $id !== null))));
    }

    protected function canResolveRelation(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        $legacyTraversal = str_starts_with($name, '_');
        $name = ltrim($name, '_');
        $targetTable = Inflector::plural(Inflector::singular($name));
        if (!$this->modelClassForTable($targetTable)) {
            return false;
        }

        $foreignKey = Inflector::singular($name) . '_id';
        if (($legacyTraversal || $name === Inflector::singular($name)) && isset($this->metadata->columns()[$foreignKey])) {
            return true;
        }

        $sourceForeign = Inflector::singular($this->tableName()) . '_id';
        return isset(self::schemaColumns($targetTable)[$sourceForeign]);
    }

    protected function joinRelation(string $name, string $type): static
    {
        $target = Inflector::plural(Inflector::singular($name));
        $foreign = Inflector::singular($name) . '_id';
        if ($name === Inflector::singular($name) && isset($this->metadata->columns()[$foreign])) {
            return $this->changeQuery($this->query->join(
                $target,
                $name . '.id',
                '=',
                $this->tableName() . '.' . $foreign,
                $type,
                $name,
            ));
        }

        $sourceForeign = Inflector::singular($this->tableName()) . '_id';
        if (isset(self::schemaColumns($target)[$sourceForeign])) {
            return $this->changeQuery($this->query->join(
                $target,
                $name . '.' . $sourceForeign,
                '=',
                $this->tableName() . '.' . $this->primaryKeyName(),
                $type,
                $name,
            ));
        }

        throw new \RuntimeException("Cannot infer relation '{$name}' for join on " . static::class . '.');
    }

    protected function modelClassForTable(string $table): ?string
    {
        $short = ucfirst(Inflector::singular($table));
        $namespace = (new \ReflectionClass(static::class))->getNamespaceName();
        foreach (array_filter([$namespace ? $namespace . '\\' . $short : null, $short]) as $class) {
            if (class_exists($class) && is_subclass_of($class, ActiveRecord::class)) {
                return $class;
            }
        }
        return null;
    }

    protected function eagerLoadPath(ActiveRecord $row, string $path): void
    {
        [$relation, $rest] = array_pad(explode('.', $path, 2), 2, null);
        $related = $row->related($relation);
        if (!$related) {
            return;
        }
        $related->load();
        $row->currentRow()->state->relationCache[$relation] = $related;
        if ($rest) {
            foreach ($related as $relatedRow) {
                $this->eagerLoadPath($relatedRow, $rest);
            }
        }
    }
}
