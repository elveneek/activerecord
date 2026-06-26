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
