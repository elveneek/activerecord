<?php

namespace Elveneek\Query;

final class CompiledQuery
{
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings = [],
        public readonly array $bindingTypes = [],
        public readonly array $dependencies = [],
    ) {
    }
}
