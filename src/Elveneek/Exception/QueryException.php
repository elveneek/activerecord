<?php
namespace Elveneek\Exception;
class QueryException extends \RuntimeException
{
    public function __construct(public readonly string $sql, public readonly array $bindings, \Throwable $previous)
    {
        parent::__construct('Query failed: ' . $previous->getMessage() . ' [SQL: ' . $sql . ']', 0, $previous);
    }
}
