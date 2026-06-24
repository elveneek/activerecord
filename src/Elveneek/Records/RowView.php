<?php

namespace Elveneek\Records;

final class RowView
{
    public ?array $visibleColumns;

    public function __construct(public RecordState $state, public array $extras = [], ?array $visibleColumns = null)
    {
        $this->visibleColumns = $visibleColumns === null ? null : array_fill_keys($visibleColumns, true);
    }

    public function exposes(string $field): bool
    {
        return $this->visibleColumns === null || isset($this->visibleColumns[$field]);
    }
}
