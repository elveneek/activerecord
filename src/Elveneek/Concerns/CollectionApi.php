<?php

namespace Elveneek\Concerns;

use Elveneek\Records\RecordState;
use Elveneek\Records\RowView;

trait CollectionApi
{
    public function getIterator(): \Traversable
    {
        return $this->ensureCollection()->getIterator(function (int $index): void {
            $this->manualIndex = $index;
        });
    }

    public function rewind(): void
    {
        $this->manualIndex = 0;
    }

    public function current(): mixed
    {
        return $this->ensureCollection()->at($this->manualIndex);
    }

    public function next(): void
    {
        $this->manualIndex++;
    }

    public function key(): mixed
    {
        return $this->manualIndex;
    }

    public function valid(): bool
    {
        return $this->ensureCollection()->at($this->manualIndex) !== null;
    }

    public function seek(int $position): static
    {
        $this->manualIndex = max(0, $position);
        return $this;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return is_numeric($offset) ? $this->ensureCollection()->at((int) $offset) : $this->__get((string) $offset);
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_numeric($offset)
            ? (int) $offset >= 0 && $this->ensureCollection()->at((int) $offset) !== null
            : $this->__isset((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset !== null) {
            $this->__set((string) $offset, $value);
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        if (is_numeric($offset)) {
            $this->ensureCollection()->unset((int) $offset);
            return;
        }
        $state = $this->currentRow()?->state;
        if ($state) {
            unset($state->attributes[(string) $offset], $state->dirty[(string) $offset], $state->loadedColumns[(string) $offset]);
        }
    }

    public function by_id(int|string $id): ?static
    {
        foreach ($this->ensureCollection()->rows() as $index => $row) {
            if ($row->state->key() == $id) {
                $this->manualIndex = $index;
                return $this->ensureCollection()->at($index);
            }
        }
        return null;
    }

    public function getRaw(string $field): mixed
    {
        return $this->currentRow()?->state->attributes[$field] ?? null;
    }

    public function setRaw(string $field, mixed $value): static
    {
        $this->currentRow(true)->state->set($field, $value);
        return $this;
    }

    public function original(?string $field = null): mixed
    {
        $original = $this->currentRow()?->state->original ?? [];
        return $field === null ? $original : ($original[$field] ?? null);
    }

    public function isNew(): bool
    {
        return $this->currentRow()?->state->status === 'new';
    }

    public function isDirty(?string $field = null): bool
    {
        return $this->currentRow()?->state->isDirty($field) ?? false;
    }

    public function dirtyAttributes(): array
    {
        return $this->currentRow()?->state->dirty ?? [];
    }

    public function wasChanged(?string $field = null): bool
    {
        $changed = $this->currentRow()?->state->wasChanged ?? [];
        return $field === null ? $changed !== [] : array_key_exists($field, $changed);
    }

    public function discardChanges(): static
    {
        $states = $this->boundRow ? [$this->boundRow->state] : $this->ensureCollection()->states();
        foreach ($states as $state) {
            $state->discardChanges();
        }
        return $this;
    }

    public function refresh(bool $force = false): static
    {
        $state = $this->currentRow()?->state;
        if (!$state || $state->key() === null) {
            throw new \RuntimeException('Cannot refresh a record without a primary key.');
        }
        if ($state->isDirty() && !$force) {
            throw new \LogicException('Cannot refresh a dirty record without force: true.');
        }
        $fresh = static::find($state->key())->withoutCache()->firstOrFail();
        $attributes = $fresh->currentRow()->state->attributes;
        $state->attributes = $state->original = $attributes;
        $state->dirty = [];
        $state->loadedColumns = array_fill_keys(array_keys($attributes), true);
        return $this;
    }

    public function reload(?string $field = null, bool $force = false): static
    {
        return $this->refresh($force);
    }

    public function toArray(): array
    {
        if ($this->boundRow) {
            return $this->serializeRow($this->boundRow);
        }
        return array_map(fn (RowView $row) => $this->serializeRow($row), $this->ensureCollection()->rows());
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
    }

    public function to_json_by_id(int|false $pretty = false): string
    {
        $result = [];
        foreach ($this->toArray() as $row) {
            $result[$row[$this->primaryKeyName()]] = $row;
        }
        return json_encode($result, $pretty === false ? 0 : $pretty, 512, JSON_THROW_ON_ERROR);
    }

    protected function serializeRow(RowView $row): array
    {
        static $serializationStack = [];
        $identity = static::class . ':' . ($row->state->key() ?? spl_object_id($row));
        if (isset($serializationStack[$identity])) {
            return [$this->primaryKeyName() => $row->state->key()];
        }
        $serializationStack[$identity] = true;
        $base = $row->visibleColumns === null ? $row->state->attributes : array_intersect_key($row->state->attributes, $row->visibleColumns);
        $attributes = array_merge($base, $row->extras);
        foreach ($this->metadata->appends() as $append) {
            $attributes[$append] = $this->modelForRow($row, $this->boundContext ?? $this->newCollection([$row], true), 0)->{$append};
        }
        foreach ($row->state->relationCache as $name => $relation) {
            if ($relation instanceof \Elveneek\ActiveRecord) {
                $attributes[$name] = $relation->toArray();
            }
        }
        $visible = $this->metadata->visible();
        $attributes = $visible
            ? array_intersect_key($attributes, array_flip($visible))
            : array_diff_key($attributes, array_flip($this->metadata->hidden()));
        unset($serializationStack[$identity]);
        return $attributes;
    }

    public function tree(mixed $root = false): array
    {
        $parent = \Elveneek\Metadata\Inflector::singular($this->tableName()) . '_id';
        $rows = $this->ensureCollection()->rows();
        $build = function ($id) use (&$build, $rows, $parent): array {
            $result = [];
            foreach ($rows as $index => $row) {
                if (($row->state->attributes[$parent] ?? null) == $id) {
                    $model = $this->ensureCollection()->at($index);
                    $model->queryTree = $build($row->state->key());
                    $result[] = $model;
                }
            }
            return $result;
        };
        return $build($root === false ? null : ($root instanceof \Elveneek\ActiveRecord ? $root->{$this->primaryKeyName()} : $root));
    }

    protected function castRow(array $data): array
    {
        foreach ($data as $field => $value) {
            $data[$field] = $this->metadata->castFromDatabase((string) $field, $value);
        }
        return $data;
    }
}
