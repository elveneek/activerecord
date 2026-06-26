<?php

namespace Elveneek\Query;

class Expression
{
    public function __construct(public readonly string $sql, public readonly array $bindings = [])
    {
    }
}
