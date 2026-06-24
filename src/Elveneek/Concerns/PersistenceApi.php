<?php

namespace Elveneek\Concerns;

use Elveneek\DB;
use Elveneek\Exception\AmbiguousWriteException;
use Elveneek\Query\Expression;
use Elveneek\Query\MySqlGrammar;
use Elveneek\Records\RecordState;
use Elveneek\Records\RowView;
use Elveneek\Scaffold;

trait PersistenceApi
{
    public static function create(array $attributes = []): static
    {
        $model = new static();
        $state = new RecordState(static::class, $model->tableName(), $model->primaryKeyName(), 'new');
        foreach ($attributes as $field => $value) {
            $state->set((string) $field, $value);
        }
        $model->collection = $model->newCollection([new RowView($state)], true);
        return $model;
    }

    public static function insert(array $attributes): static
    {
        return static::create($attributes)->save();
    }

    public static function insertAll(array $rows): static
    {
        $model = static::create();
        foreach ($rows as $row) {
            $model->addRow($row);
        }
        return $model->saveAll();
    }

    public function addRow(?array $attributes = null): static
    {
        $collection = $this->ensureCollection();
        $states = $collection->states();
        $last = end($states);
        if ($attributes !== null && $last instanceof RecordState && $last->status === 'new' && $last->attributes === []) {
            foreach ($attributes as $field => $value) {
                $last->set((string) $field, $value);
            }
            return $this;
        }
        $state = new RecordState(static::class, $this->tableName(), $this->primaryKeyName(), 'new');
        $state->placeholder = $attributes === null;
        foreach ($attributes ?? [] as $field => $value) {
            $state->set((string) $field, $value);
        }
        $collection->add(new RowView($state));
        $this->manualIndex = $collection->countLoaded() - 1;
        return $this;
    }

    public function save(): static
    {
        $states = $this->statesForSave();
        if (count($states) > 1) {
            throw new AmbiguousWriteException('save() cannot persist more than one changed row; use saveAll().');
        }
        $this->affectedRowsValue = $states ? $this->saveState($states[0]) : 0;
        return $this;
    }

    public function saveCurrent(): static
    {
        $state = $this->currentRow()?->state;
        $this->affectedRowsValue = $state ? $this->saveState($state) : 0;
        return $this;
    }

    public function saveAll(): static
    {
        $states = array_values(array_filter(
            $this->boundRow ? [$this->boundRow->state] : $this->ensureCollection()->loadedStates(),
            static fn (RecordState $state) => ($state->status === 'new' && !$state->placeholder) || $state->isDirty(),
        ));
        if (!$states) {
            $this->affectedRowsValue = 0;
            return $this;
        }
        $snapshots = [];
        foreach ($states as $state) {
            $snapshots[spl_object_id($state)] = [$state->attributes, $state->original, $state->dirty, $state->status];
        }
        $this->affectedRowsValue = 0;
        $this->lastSaveErrorsValue = [];
        try {
            DB::transaction(function () use ($states): void {
                foreach ($states as $state) {
                    $this->affectedRowsValue += $this->saveState($state);
                }
            });
        } catch (\Throwable $exception) {
            foreach ($states as $state) {
                [$state->attributes, $state->original, $state->dirty, $state->status] = $snapshots[spl_object_id($state)];
            }
            $this->lastSaveErrorsValue[] = $exception->getMessage();
            throw $exception;
        }
        return $this;
    }

    public function affectedRows(): int
    {
        return $this->affectedRowsValue;
    }

    public function lastSaveErrors(): array
    {
        return $this->lastSaveErrorsValue;
    }

    protected function statesForSave(): array
    {
        $states = $this->boundRow ? [$this->boundRow->state] : $this->ensureCollection()->loadedStates();
        return array_values(array_filter($states, static fn (RecordState $state) => ($state->status === 'new' && !$state->placeholder) || $state->isDirty()));
    }

    protected function saveState(RecordState $state): int
    {
        if ($state->status === 'new') {
            $this->ensureWritableColumns(array_keys($state->attributes));
            $columns = $this->metadata->columns(true);
            $values = $state->attributes;
            $now = date('Y-m-d H:i:s');
            if (isset($columns['created_at']) && !array_key_exists('created_at', $values)) {
                $values['created_at'] = $now;
            }
            if (isset($columns['updated_at']) && !array_key_exists('updated_at', $values)) {
                $values['updated_at'] = $now;
            }
            $fields = array_keys($values);
            [$valueSql, $bindings] = $this->compileValues($fields, $values);
            $sql = 'INSERT INTO ' . MySqlGrammar::quoteIdentifier($this->tableName()) . ' ('
                . implode(', ', array_map([MySqlGrammar::class, 'quoteIdentifier'], $fields)) . ') VALUES ('
                . implode(', ', $valueSql) . ')';
            $statement = DB::execute($sql, $bindings, 'default', static::class);
            $id = DB::connection()->lastInsertId();
            $id = ctype_digit((string) $id) ? (int) $id : $id;
            $state->attributes[$this->primaryKeyName()] = $id;
            $this->insert_id = $id;
            if (isset($columns['sort']) && empty($values['sort'])) {
                DB::execute('UPDATE ' . MySqlGrammar::quoteIdentifier($this->tableName()) . ' SET `sort` = ? WHERE '
                    . MySqlGrammar::quoteIdentifier($this->primaryKeyName()) . ' = ?', [$id, $id], 'default', static::class);
                $state->attributes['sort'] = $id;
            }
            foreach ($values as $field => $value) {
                if (!$value instanceof Expression) {
                    $state->attributes[$field] = $value;
                }
            }
            $state->markSaved();
            self::identity()->put($this->connectionKey(), $state);
            self::bumpGeneration($this->tableName());
            return max(1, $statement->rowCount());
        }
        if (!$state->isDirty()) {
            return 0;
        }
        if ($state->key() === null) {
            throw new \Elveneek\Exception\ReadOnlyRecordException('A persisted projection without a primary key cannot be saved.');
        }
        $this->ensureWritableColumns(array_keys($state->dirty));
        $columns = $this->metadata->columns(true);
        $values = $state->dirty;
        $versionColumn = $this->configuredStatic('versionColumn');
        $versionOriginal = null;
        if (is_string($versionColumn) && array_key_exists($versionColumn, $state->original)) {
            $versionOriginal = (int) $state->original[$versionColumn];
            $values[$versionColumn] = $versionOriginal + 1;
        }
        if (isset($columns['updated_at']) && !array_key_exists('updated_at', $values)) {
            $values['updated_at'] = date('Y-m-d H:i:s');
        }
        $fields = array_keys($values);
        [$valueSql, $bindings] = $this->compileValues($fields, $values);
        $sets = [];
        foreach ($fields as $index => $field) {
            $sets[] = MySqlGrammar::quoteIdentifier($field) . ' = ' . $valueSql[$index];
        }
        $bindings[] = $state->key();
        $where = ' WHERE ' . MySqlGrammar::quoteIdentifier($this->primaryKeyName()) . ' = ?';
        if ($versionOriginal !== null) {
            $where .= ' AND ' . MySqlGrammar::quoteIdentifier($versionColumn) . ' = ?';
            $bindings[] = $versionOriginal;
        }
        $statement = DB::execute('UPDATE ' . MySqlGrammar::quoteIdentifier($this->tableName()) . ' SET '
            . implode(', ', $sets) . $where, $bindings, 'default', static::class);
        if ($versionOriginal !== null && $statement->rowCount() === 0) {
            throw new \Elveneek\Exception\StaleModelException('Optimistic lock failed for ' . static::class . ':' . $state->key());
        }
        foreach ($values as $field => $value) {
            if (!$value instanceof Expression) {
                $state->attributes[$field] = $value;
            }
        }
        $state->markSaved();
        $state->relationCache = [];
        self::bumpGeneration($this->tableName());
        return $statement->rowCount();
    }

    protected function compileValues(array $fields, array $values): array
    {
        $sql = $bindings = [];
        foreach ($fields as $field) {
            $value = $values[$field];
            if ($value instanceof Expression) {
                $sql[] = $value->sql;
                array_push($bindings, ...$value->bindings);
            } else {
                $sql[] = '?';
                $bindings[] = $this->metadata->castForDatabase($field, $value);
            }
        }
        return [$sql, $bindings];
    }

    protected function ensureWritableColumns(array $fields): void
    {
        $columns = $this->metadata->columns(true);
        foreach ($fields as $field) {
            if (!isset($columns[$field])) {
                if (!self::$schemaEvolution) {
                    throw new \RuntimeException("Unknown column {$this->tableName()}.{$field}");
                }
                Scaffold::create_field($this->tableName(), $field);
                self::flushSchemaCache();
                $this->metadata = self::metadataFor(static::class);
                $columns = $this->metadata->columns(true);
            }
        }
    }

    public function fill(array $attributes, ?array $only = null): static
    {
        $allowed = $only ?? $this->configuredStatic('fillable');
        if (!is_array($allowed)) {
            throw new \Elveneek\Exception\MassAssignmentException('fill() requires an explicit only list or model $fillable.');
        }
        foreach ($attributes as $field => $value) {
            if (in_array($field, $allowed, true)) {
                $this->{$field} = $value;
            }
        }
        return $this;
    }

    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $field => $value) {
            $this->{$field} = $value;
        }
        return $this;
    }

    protected function compiledWhere(): array
    {
        if (!$this->query->wheres) {
            return ['', []];
        }
        $bindings = [];
        $where = (new MySqlGrammar())->compilePredicates($this->query->wheres, $bindings);
        return [' WHERE ' . $where, $bindings];
    }

    public function updateAll(array $attributes): int
    {
        if (!$attributes) {
            return 0;
        }
        $bindings = $sets = [];
        foreach ($attributes as $field => $value) {
            MySqlGrammar::assertIdentifier((string) $field);
            if ($value instanceof Expression) {
                $sets[] = MySqlGrammar::quoteIdentifier((string) $field) . ' = ' . $value->sql;
                array_push($bindings, ...$value->bindings);
            } else {
                $sets[] = MySqlGrammar::quoteIdentifier((string) $field) . ' = ?';
                $bindings[] = $this->metadata->castForDatabase((string) $field, $value);
            }
        }
        [$where, $whereBindings] = $this->compiledWhere();
        $statement = DB::execute('UPDATE ' . MySqlGrammar::quoteIdentifier($this->tableName()) . ' SET '
            . implode(', ', $sets) . $where, array_merge($bindings, $whereBindings), 'default', static::class);
        self::invalidateTable($this->tableName());
        return $statement->rowCount();
    }

    public function increment(string $field, int|float $amount = 1): int
    {
        MySqlGrammar::assertIdentifier($field);
        return $this->updateAll([$field => new Expression(MySqlGrammar::quoteIdentifier($field) . ' + ?', [$amount])]);
    }

    public function decrement(string $field, int|float $amount = 1): int
    {
        return $this->increment($field, -$amount);
    }

    public function delete(): static
    {
        $row = $this->currentRow();
        $states = $this->boundRow ? [$this->boundRow->state] : ($row ? [$row->state] : []);
        if (count($states) !== 1) {
            throw new AmbiguousWriteException('delete() requires exactly one row; use deleteAll() for a set.');
        }
        $state = $states[0];
        if ($state->key() === null) {
            throw new AmbiguousWriteException('Cannot delete a record without a primary key.');
        }
        $sql = 'DELETE FROM ' . MySqlGrammar::quoteIdentifier($this->tableName()) . ' WHERE '
            . MySqlGrammar::quoteIdentifier($this->primaryKeyName()) . ' = ?';
        $this->affectedRowsValue = DB::execute($sql, [$state->key()], 'default', static::class)->rowCount();
        $state->status = 'deleted';
        self::identity()->invalidate($this->connectionKey(), static::class, $state->key());
        self::identity()->markMissing($this->connectionKey(), static::class, $state->key());
        self::bumpGeneration($this->tableName());
        return $this;
    }

    public function deleteAll(): int
    {
        [$where, $bindings] = $this->compiledWhere();
        $statement = DB::execute('DELETE FROM ' . MySqlGrammar::quoteIdentifier($this->tableName()) . $where, $bindings, 'default', static::class);
        self::invalidateTable($this->tableName());
        return $statement->rowCount();
    }

    public static function truncate(bool $areYouSure = false, ?bool $confirm = null): void
    {
        if (($confirm ?? $areYouSure) !== true) {
            throw new \Exception('You must pass true to the $areYouSure parameter to truncate the table.');
        }
        $model = new static();
        DB::connection()->exec('TRUNCATE TABLE ' . MySqlGrammar::quoteIdentifier($model->tableName()));
        self::invalidateTable($model->tableName());
    }

    public static function firstOrCreate(array $where, array $values = []): static
    {
        return static::where($where)->first() ?? static::create(array_merge($where, $values))->save();
    }

    public static function updateOrCreate(array $where, array $values = []): static
    {
        $model = static::where($where)->first() ?? static::create($where);
        return $model->forceFill($values)->save();
    }

    public static function upsert(array $rows, array $uniqueBy, array $update): int
    {
        if (!$rows) {
            return 0;
        }
        $model = new static();
        $fields = array_keys($rows[0]);
        $bindings = $valuesSql = [];
        foreach ($rows as $row) {
            $valuesSql[] = '(' . implode(', ', array_fill(0, count($fields), '?')) . ')';
            foreach ($fields as $field) {
                $bindings[] = $row[$field] ?? null;
            }
        }
        $updates = array_map(static fn ($field) => MySqlGrammar::quoteIdentifier($field) . ' = VALUES(' . MySqlGrammar::quoteIdentifier($field) . ')', $update);
        $sql = 'INSERT INTO ' . MySqlGrammar::quoteIdentifier($model->tableName()) . ' ('
            . implode(', ', array_map([MySqlGrammar::class, 'quoteIdentifier'], $fields)) . ') VALUES '
            . implode(', ', $valuesSql) . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
        $count = DB::execute($sql, $bindings, 'default', static::class)->rowCount();
        self::invalidateTable($model->tableName());
        return $count;
    }

    public function ioi(): mixed
    {
        return $this->insert_id ?: $this->__get($this->primaryKeyName());
    }
}
