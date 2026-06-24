<?php

namespace Elveneek\Concerns;

use Elveneek\DB;
use Elveneek\Metadata\Inflector;
use Elveneek\Query\MySqlGrammar;

trait LegacyRelationsApi
{
    protected function legacyRelationIds(string $name): array
    {
        $target = Inflector::plural(Inflector::singular($name));
        $pivot = $this->pivotTable($this->tableName(), $target);
        if (!$pivot) {
            return $this->related($name)?->pluck($this->primaryKeyName()) ?? [];
        }
        $sourceKey = Inflector::singular($this->tableName()) . '_id';
        $targetKey = Inflector::singular($target) . '_id';
        $sourceIds = $this->pluck($this->primaryKeyName());
        if (!$sourceIds) {
            return [];
        }
        $sql = 'SELECT ' . MySqlGrammar::quoteIdentifier($targetKey) . ' FROM ' . MySqlGrammar::quoteIdentifier($pivot)
            . ' WHERE ' . MySqlGrammar::quoteIdentifier($sourceKey) . ' IN (' . implode(',', array_fill(0, count($sourceIds), '?')) . ')'
            . ' ORDER BY ' . MySqlGrammar::quoteIdentifier('sort');
        return array_map('intval', DB::execute($sql, $sourceIds)->fetchAll(\PDO::FETCH_COLUMN));
    }
}
