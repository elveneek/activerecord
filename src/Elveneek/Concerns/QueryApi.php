<?php

namespace Elveneek\Concerns;

use Elveneek\ActiveRecord;
use Elveneek\DB;
use Elveneek\Exception\ModelNotFoundException;
use Elveneek\Query\MySqlGrammar;
use Elveneek\Query\QueryBuilder;

trait QueryApi
{
    public function toQuery(): QueryBuilder
    {
        return $this->query;
    }

    public static function fromQuery(QueryBuilder $query): static
    {
        $model = new static();
        if ($query->table !== $model->tableName()) {
            throw new \Elveneek\Exception\IncompatibleQueryException("Query table {$query->table} is incompatible with " . static::class . '.');
        }
        $hasAllColumns = $query->columns === [] || in_array('*', $query->columns, true) || in_array($model->tableName() . '.*', $query->columns, true);
        $hasPrimaryKey = in_array($model->primaryKeyName(), $query->columns, true) || in_array($model->tableName() . '.' . $model->primaryKeyName(), $query->columns, true);
        if ($query->groups !== [] || (!$hasAllColumns && !$hasPrimaryKey)) {
            throw new \Elveneek\Exception\IncompatibleQueryException('The query does not represent writable model rows with a primary key.');
        }        $model->query = $query;
        return $model;
    }

    public function toSql(): string
    {
        return $this->query->toSql();
    }

    public function bindings(): array
    {
        return $this->query->bindings();
    }

    public function toRawSql(): string
    {
        $sql = $this->toSql();
        foreach ($this->bindings() as $binding) {
            $value = $binding === null ? 'NULL' : self::$db->quote((string) $binding);
            $sql = preg_replace('/\?/', $value, $sql, 1);
        }
        return $sql;
    }

    public function queryFingerprint(): string
    {
        return $this->query->fingerprint();
    }

    public function queryDependencies(): array
    {
        return $this->query->dependencies();
    }

    public function copy(): static
    {
        $copy = new static();
        $copy->query = $this->query;
        return $copy;
    }

    public function resetQuery(): static
    {
        return new static();
    }

    public function findOne(int|string $id): static
    {
        return $this->changeQuery($this->query->whereKey($id));
    }

    public function first(): ?static
    {
        $result = $this->ensureCollection()->at(0);
        if ($result === null && count($this->query->lookupIds ?? []) === 1) {
            self::identity()->markMissing($this->connectionKey(), static::class, $this->query->lookupIds[0]);
        }
        return $result;
    }

    public function firstOrFail(): static
    {
        return $this->first() ?? throw new ModelNotFoundException(static::class . ' query returned no rows.');
    }

    public function last(): ?static
    {
        $rows = $this->ensureCollection()->rows();
        return $rows ? $this->ensureCollection()->at(count($rows) - 1) : null;
    }

    public function exists(): bool
    {
        return $this->first() !== null;
    }

    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    public function isEmpty(): bool
    {
        return !$this->exists();
    }

    public function isNotEmpty(): bool
    {
        return $this->exists();
    }

    public function ne(): bool
    {
        return $this->exists();
    }

    public function count(): int
    {
        if ($this->boundRow) {
            return 1;
        }
        if ($this->collection?->isFullyLoaded()) {
            return $this->collection->countLoaded();
        }
        $compiled = (new MySqlGrammar())->compileCount($this->query);
        return (int) DB::execute($compiled->sql, $compiled->bindings, 'default', static::class)->fetchColumn();
    }

    public function foundRows(): int
    {
        if ($this->knownTotal !== null) {
            return $this->knownTotal;
        }
        $compiled = (new MySqlGrammar())->compileCount($this->query, true);
        return $this->knownTotal = (int) DB::execute($compiled->sql, $compiled->bindings, 'default', static::class)->fetchColumn();
    }

    public function total(): int
    {
        return $this->foundRows();
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->foundRows() / $this->per_page));
    }

    public function hasNextPage(): bool
    {
        return ($this->current_page + 1) < $this->lastPage();
    }

    public function paginate(int $perPage = 10, int|false $page = false): static
    {
        $page = $page === false ? (int) ($_GET['page'] ?? 0) : $page;
        if ($perPage < 1) {
            throw new \InvalidArgumentException('Items per page must be greater than 0');
        }
        if ($page < 0) {
            throw new \InvalidArgumentException('Page number must be 0 or greater');
        }
        $this->per_page = $perPage;
        $this->current_page = $page;
        $this->query = $this->query->limit($perPage)->offset($page * $perPage);
        $this->collection = null;
        return $this;
    }

    public function simplePaginate(int $perPage = 10, int $page = 0): static
    {
        return $this->paginate($perPage, $page);
    }

    public function value(string $field): mixed
    {
        return $this->first()?->{$field};
    }

    public function pluck(string $field, ?string $key = null): array
    {
        $result = [];
        foreach ($this as $row) {
            $value = $row->{$field};
            if ($key === null) {
                if ($value !== null) {
                    $result[] = $value;
                }
            } else {
                $result[$row->{$key}] = $value;
            }
        }
        return $result;
    }

    public function load(): static
    {
        $this->ensureCollection()->loadAll();
        return $this;
    }

    public function isLoaded(): bool
    {
        return $this->collection !== null;
    }

    public function loadedCount(): int
    {
        return $this->collection?->countLoaded() ?? 0;
    }

    public function isFullyLoaded(): bool
    {
        return $this->collection?->isFullyLoaded() ?? false;
    }

    public function cacheHit(): bool
    {
        return $this->cacheSourceValue !== 'database';
    }

    public function cacheSource(): string
    {
        return $this->cacheSourceValue;
    }

    protected function aggregate(string $function, string $field): int|float|null
    {
        MySqlGrammar::assertIdentifier($field);
        $query = $this->query->withoutOrder()->withoutLimitOffset()
            ->selectRaw(strtoupper($function) . '(' . MySqlGrammar::quoteIdentifier($field) . ') AS aggregate');
        $compiled = (new MySqlGrammar())->compileSelect($query);
        $value = DB::execute($compiled->sql, $compiled->bindings, 'default', static::class)->fetchColumn();
        return $value === false || $value === null ? null : $value + 0;
    }

    protected function search(string|array $fields, string $term): static
    {
        $fields = is_array($fields) ? $fields : [$fields];
        $query = $this->query->whereGroup(function ($query) use ($fields, $term) {
            foreach ($fields as $index => $field) {
                $query = $index ? $query->orWhereLike($field, '%' . $term . '%') : $query->whereLike($field, '%' . $term . '%');
            }
            return $query;
        });
        return $this->changeQuery($query);
    }

    protected function isLegacyOrder(string $order): bool
    {
        return str_contains($order, ',') || str_contains($order, '(') || (bool) preg_match('/\s/', trim($order));
    }

    protected function legacyOrder(string $order): static
    {
        $query = $this->query;
        if (trim($order) === '') {
            return $this->changeQuery($query->withoutOrder());
        }
        foreach (explode(',', $order) as $part) {
            $part = trim($part);
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_.]*)\s+(ASC|DESC)$/i', $part, $matches)) {
                $query = $query->orderBy($matches[1], $matches[2]);
            } elseif (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $part)) {
                $query = $query->orderBy($part);
            } else {
                $query = $query->orderByRaw($part);
            }
        }
        return $this->changeQuery($query);
    }

    protected function legacyGroup(string $group): static
    {
        return $this->changeQuery($this->query->groupBy(array_map('trim', explode(',', $group))));
    }

    public function eachById(int $size, callable $callback): void
    {
        $this->chunkById($size, static function (ActiveRecord $chunk) use ($callback): void {
            foreach ($chunk as $row) {
                $callback($row);
            }
        });
    }

    public function chunkById(int $size, callable $callback): void
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('Chunk size must be greater than zero.');
        }
        $baseQuery = $this->query->withoutOrder()->withoutLimitOffset();
        $last = null;
        while (true) {
            $chunk = $this->copy();
            $chunk->query = $baseQuery->orderBy($this->primaryKeyName())->limit($size);
            $chunk->collection = null;
            if ($last !== null) {
                $chunk->where($this->primaryKeyName(), '>', $last);
            }
            if ($chunk->isEmpty()) {
                break;
            }
            $callback($chunk);
            $last = $chunk->last()?->{$this->primaryKeyName()};
            if ($chunk->count() < $size) {
                break;
            }
        }
    }
}
