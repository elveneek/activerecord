<?php

namespace Elveneek\Concerns;

use Elveneek\ActiveRecord;
use Elveneek\Relations\RelationDefinition;

trait ExplicitRelationsApi
{
    public function belongsTo(string $model, ?string $foreignKey = null, string $ownerKey = 'id'): RelationDefinition
    {
        return new RelationDefinition($this, 'belongsTo', $model, $foreignKey, null, 'id', $ownerKey);
    }

    public function hasMany(string $model, ?string $foreignKey = null, string $localKey = 'id'): RelationDefinition
    {
        return new RelationDefinition($this, 'hasMany', $model, $foreignKey, null, $localKey);
    }

    public function belongsToMany(string $model, ?string $pivotTable = null, string $localKey = 'id', string $targetKey = 'id'): RelationDefinition
    {
        return new RelationDefinition($this, 'belongsToMany', $model, null, $pivotTable, $localKey, $targetKey);
    }
}
