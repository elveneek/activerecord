<?php

namespace Elveneek\Concerns;

trait FluentApi
{
    public function when(mixed $value, callable $callback, ?callable $default = null): static
    {
        if ($value) {
            return $callback($this, $value) ?? $this;
        }
        return $default ? ($default($this, $value) ?? $this) : $this;
    }

    public function unless(mixed $value, callable $callback, ?callable $default = null): static
    {
        return $this->when(!$value, fn ($query) => $callback($query, $value), $default);
    }

    public function tap(callable $callback): static
    {
        $callback($this);
        return $this;
    }

    public function orStub(): static
    {
        return $this->isEmpty() ? $this->__call('stub', []) : $this;
    }

    public function get(string $field, bool $multilang = false): mixed
    {
        $row = $this->currentRow();
        if ($row && $row->exposes($field) && array_key_exists($field, $row->state->attributes)) {
            return $row->state->attributes[$field];
        }
        if ($row && array_key_exists($field, $row->extras)) {
            return $row->extras[$field];
        }
        if (isset($this->metadata->columns()[$field])) {
            if (self::$strict && $row && !$row->exposes($field)) {
                throw new \Elveneek\Exception\MissingAttributeException("Attribute '{$field}' was not selected for " . $this->modelLabel() . '.');
            }
            return null;
        }
        return $this->__get($field);
    }

    public function only(string $field): mixed
    {
        return $this->value($field);
    }
    public static function schemaMode(\Elveneek\SchemaMode|string $mode): void
    {
        $mode = $mode instanceof \Elveneek\SchemaMode ? $mode : \Elveneek\SchemaMode::from(strtolower($mode));
        self::schemaEvolution($mode === \Elveneek\SchemaMode::Evolve);
    }
}
