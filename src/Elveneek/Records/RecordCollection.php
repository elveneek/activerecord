<?php

namespace Elveneek\Records;

final class RecordCollection
{
    private array $rows = [];
    private bool $fullyLoaded = false;

    public function __construct(
        private readonly string $modelClass,
        private readonly ?\PDOStatement $statement,
        private readonly \Closure $hydrate,
        private readonly \Closure $modelFactory,
        array $rows = [],
        bool $fullyLoaded = false,
    ) {
        $this->rows = array_values($rows);
        $this->fullyLoaded = $fullyLoaded || $statement === null;
    }

    public function __destruct()
    {
        if (!$this->fullyLoaded) {
            $this->statement?->closeCursor();
        }
    }
    public function at(int $index): mixed
    {
        if ($index < 0) {
            return null;
        }
        $this->loadThrough($index);
        $row = $this->rows[$index] ?? null;
        return $row ? ($this->modelFactory)($row, $this, $index) : null;
    }

    public function rowAt(int $index): ?RowView
    {
        $this->loadThrough($index);
        return $this->rows[$index] ?? null;
    }

    public function loadAll(): void
    {
        while (!$this->fullyLoaded) {
            $this->fetchOne();
        }
    }

    public function rows(): array
    {
        $this->loadAll();
        return array_values(array_filter($this->rows));
    }

    public function loadedStates(): array
    {
        $states = [];
        foreach (array_filter($this->rows) as $row) {
            $states[spl_object_id($row->state)] = $row->state;
        }
        return array_values($states);
    }

    public function hasChanges(): bool
    {
        foreach ($this->loadedStates() as $state) {
            if ($state->isDirty() || $state->status === 'new') {
                return true;
            }
        }
        return false;
    }
    public function states(): array
    {
        $states = [];
        foreach ($this->rows() as $row) {
            $states[spl_object_id($row->state)] = $row->state;
        }
        return array_values($states);
    }

    public function add(RowView $row): void
    {
        $this->rows[] = $row;
    }

    public function unset(int $index): void
    {
        $this->loadAll();
        if (array_key_exists($index, $this->rows)) {
            $this->rows[$index] = null;
        }
    }

    public function countLoaded(): int
    {
        return count(array_filter($this->rows));
    }

    public function isFullyLoaded(): bool
    {
        return $this->fullyLoaded;
    }

    public function getIterator(?callable $onYield = null): \Traversable
    {
        $index = 0;
        while (true) {
            $model = $this->at($index);
            if ($model === null) {
                if ($this->fullyLoaded && $index >= count($this->rows)) {
                    break;
                }
                $index++;
                continue;
            }            $onYield && $onYield($index);
            yield $index => $model;
            $index++;
        }
        $onYield && $onYield($index);
    }

    private function loadThrough(int $index): void
    {
        while (!$this->fullyLoaded && count($this->rows) <= $index) {
            $this->fetchOne();
        }
    }

    private function fetchOne(): void
    {
        $data = $this->statement?->fetch(\PDO::FETCH_ASSOC);
        if ($data === false || $data === null) {
            $this->fullyLoaded = true;
            $this->statement?->closeCursor();
            return;
        }
        $this->rows[] = ($this->hydrate)($data);
    }
}
