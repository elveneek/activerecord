<?php

namespace Elveneek;

use Elveneek\Query\MySqlGrammar;

final class TableRecord extends ActiveRecord
{
    protected string $runtimeTable;

    public function __construct(string $table)
    {
        MySqlGrammar::assertIdentifier($table);
        $this->runtimeTable = $table;
        parent::__construct();
    }

    public static function forTable(string $table): self
    {
        return new self($table);
    }

    protected function newInstance(): static
    {
        return new static($this->runtimeTable);
    }

    protected function modelKey(): string
    {
        return static::class . ':' . $this->runtimeTable;
    }

    protected function modelLabel(): string
    {
        return 'table:' . $this->runtimeTable;
    }

    protected function metadataTableOverride(): ?string
    {
        return $this->runtimeTable;
    }
}