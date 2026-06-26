<?php

namespace Elveneek\Query;

final class MutableQueryProxy
{
    public function __construct(private QueryBuilder $query)
    {
    }

    public function __call(string $method, array $arguments): self
    {
        if (!method_exists($this->query, $method)) {
            throw new \BadMethodCallException("QueryBuilder::{$method}() does not exist.");
        }
        $result = $this->query->{$method}(...$arguments);
        if (!$result instanceof QueryBuilder) {
            throw new \LogicException("{$method} is not a fluent query method.");
        }
        $this->query = $result;
        return $this;
    }

    public function query(): QueryBuilder
    {
        return $this->query;
    }
}
