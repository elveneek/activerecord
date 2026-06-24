<?php

namespace Elveneek\Cache;

use Elveneek\Records\RecordState;

final class IdentityMap
{
    private array $states = [];
    private array $missing = [];

    public function get(string $connection, string $modelClass, int|string $id): ?RecordState
    {
        return $this->states[$this->key($connection, $modelClass, $id)] ?? null;
    }

    public function put(string $connection, RecordState $state): RecordState
    {
        $id = $state->key();
        if ($id === null) {
            return $state;
        }
        $key = $this->key($connection, $state->modelClass, $id);
        if (isset($this->states[$key])) {
            return $this->states[$key];
        }
        unset($this->missing[$key]);
        return $this->states[$key] = $state;
    }

    public function markMissing(string $connection, string $modelClass, int|string $id): void
    {
        $this->missing[$this->key($connection, $modelClass, $id)] = true;
    }

    public function isMissing(string $connection, string $modelClass, int|string $id): bool
    {
        return isset($this->missing[$this->key($connection, $modelClass, $id)]);
    }

    public function invalidate(string $connection, string $modelClass, int|string $id): void
    {
        unset($this->states[$this->key($connection, $modelClass, $id)]);
    }

    public function invalidateTable(string $connection, string $table): void
    {
        foreach ($this->states as $key => $state) {
            if (str_starts_with($key, $connection . '|') && $state->table === $table) {
                unset($this->states[$key]);
            }
        }
    }

    public function snapshot(): array
    {
        $snapshot = [];
        foreach ($this->states as $state) {
            $snapshot[spl_object_id($state)] = [
                'state' => $state,
                'attributes' => $state->attributes,
                'original' => $state->original,
                'dirty' => $state->dirty,
                'wasChanged' => $state->wasChanged,
                'loadedColumns' => $state->loadedColumns,
                'relationCache' => $state->relationCache,
                'status' => $state->status,
            ];
        }
        return ['states' => $snapshot, 'map' => $this->states, 'missing' => $this->missing];
    }

    public function restore(array $snapshot): void
    {
        foreach ($snapshot['states'] ?? [] as $saved) {
            $state = $saved['state'];
            $state->attributes = $saved['attributes'];
            $state->original = $saved['original'];
            $state->dirty = $saved['dirty'];
            $state->wasChanged = $saved['wasChanged'];
            $state->loadedColumns = $saved['loadedColumns'];
            $state->relationCache = $saved['relationCache'];
            $state->status = $saved['status'];
        }
        $this->states = $snapshot['map'] ?? [];
        $this->missing = $snapshot['missing'] ?? [];
    }
    public function clear(): void
    {
        $this->states = [];
        $this->missing = [];
    }

    private function key(string $connection, string $modelClass, int|string $id): string
    {
        return $connection . '|' . $modelClass . '|' . get_debug_type($id) . ':' . $id;
    }
}
