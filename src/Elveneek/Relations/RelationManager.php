<?php

namespace Elveneek\Relations;

use Elveneek\ActiveRecord;
use Elveneek\Metadata\Inflector;

/** Manager for inferred direct belongs-to/has-many relations. */
final class RelationManager
{
    public function __construct(private ActiveRecord $owner, private string $name)
    {
    }

    public function get(): ?ActiveRecord
    {
        return $this->owner->related($this->name);
    }

    public function associate(?ActiveRecord $record): ActiveRecord
    {
        if ($this->name !== Inflector::singular($this->name)) {
            throw new \LogicException('associate() is available only for a direct belongs-to relation.');
        }
        $this->owner->{$this->name . '_id'} = $record?->id;
        return $this->owner;
    }

    public function dissociate(): ActiveRecord
    {
        return $this->associate(null);
    }

    public function attach(int|array $ids, array $attributes = []): self
    {
        throw $this->explicitManyToManyRequired('attach');
    }

    public function detach(int|array|null $ids = null): self
    {
        throw $this->explicitManyToManyRequired('detach');
    }

    public function sync(array $ids): self
    {
        throw $this->explicitManyToManyRequired('sync');
    }

    private function explicitManyToManyRequired(string $operation): \LogicException
    {
        return new \LogicException(
            "{$operation}() requires an explicitly declared belongsToMany() relation; pivot tables are not inferred.",
        );
    }
}
