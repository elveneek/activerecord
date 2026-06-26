<?php

namespace Elveneek\Records;

final class RecordState
{
    public array $attributes = [];
    public array $original = [];
    public array $dirty = [];
    public array $wasChanged = [];
    public array $loadedColumns = [];
    public array $relationCache = [];
    public bool $placeholder = false;

    public function __construct(
        public readonly string $modelClass,
        public readonly string $table,
        public readonly string $primaryKey,
        public string $status = 'persisted',
        array $attributes = [],
        array $loadedColumns = [],
    ) {
        $this->attributes = $attributes;
        $this->original = $attributes;
        $this->loadedColumns = array_fill_keys($loadedColumns ?: array_keys($attributes), true);
    }

    public function key(): int|string|null
    {
        return $this->attributes[$this->primaryKey] ?? null;
    }

    public function set(string $field, mixed $value): void
    {
        $this->placeholder = false;
        if ($this->status === 'deleted') {
            throw new \LogicException('A deleted record cannot be changed.');
        }
        $this->attributes[$field] = $value;
        $this->loadedColumns[$field] = true;
        if ($this->status === 'new' || !array_key_exists($field, $this->original) || $this->original[$field] !== $value) {
            $this->dirty[$field] = $value;
        } else {
            unset($this->dirty[$field]);
        }
    }

    public function merge(array $attributes, array $loadedColumns): void
    {
        foreach ($attributes as $field => $value) {
            if (!array_key_exists($field, $this->dirty)) {
                $this->attributes[$field] = $value;
                $this->original[$field] = $value;
            }
            $this->loadedColumns[$field] = true;
        }
        foreach ($loadedColumns as $field) {
            $this->loadedColumns[$field] = true;
        }
    }

    public function markSaved(array $databaseValues = []): void
    {
        foreach ($databaseValues as $field => $value) {
            $this->attributes[$field] = $value;
        }
        $this->wasChanged = $this->dirty;
        $this->original = $this->attributes;
        $this->dirty = [];
        $this->status = 'persisted';
        $this->loadedColumns = array_fill_keys(array_keys($this->attributes), true);
    }

    public function discardChanges(): void
    {
        $this->attributes = $this->original;
        $this->dirty = [];
        $this->wasChanged = [];
    }

    public function isDirty(?string $field = null): bool
    {
        return $field === null ? $this->dirty !== [] : array_key_exists($field, $this->dirty);
    }
}
