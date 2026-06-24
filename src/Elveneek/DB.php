<?php

namespace Elveneek;

use Elveneek\Exception\QueryException;
use Elveneek\Query\Expression;
use Elveneek\Query\MySqlGrammar;
use Elveneek\Query\QueryBuilder;

final class DB
{
    private static array $connections = [];
    private static array $queryLog = [];
    private static array $listeners = [];
    private static bool $logging = true;
    private static array $transactionLevels = [];
    private static array $afterCommit = [];

    public static function setConnection(\PDO $connection, string $name = 'default'): void
    {
        self::$connections[$name] = $connection;
        if ($name === 'default') {
            ActiveRecord::$db = $connection;
        }
    }

    public static function connection(string $name = 'default'): \PDO
    {
        $connection = self::$connections[$name] ?? ($name === 'default' ? ActiveRecord::$db : null);
        if (!$connection instanceof \PDO) {
            throw new \RuntimeException("Database connection '{$name}' is not configured.");
        }
        return $connection;
    }

    public static function table(string $table): QueryBuilder
    {
        return new QueryBuilder($table);
    }

    public static function raw(string $sql, array $bindings = []): Expression
    {
        return new Raw($sql, $bindings);
    }

    public static function now(): Expression
    {
        return self::raw('CURRENT_TIMESTAMP');
    }

    public static function runQuery(QueryBuilder $query, string $connection = 'default'): array
    {
        $compiled = (new MySqlGrammar())->compileSelect($query);
        $statement = self::execute($compiled->sql, $compiled->bindings, $connection, $query->modelClass);
        return $statement->fetchAll(\PDO::FETCH_OBJ);
    }

    public static function execute(string $sql, array $bindings = [], string $connection = 'default', ?string $model = null): \PDOStatement
    {
        $started = microtime(true);
        try {
            $statement = self::connection($connection)->prepare($sql);
            foreach (array_values($bindings) as $index => $value) {
                $type = match (true) {
                    $value === null => \PDO::PARAM_NULL,
                    is_bool($value) => \PDO::PARAM_BOOL,
                    is_int($value) => \PDO::PARAM_INT,
                    default => \PDO::PARAM_STR,
                };
                $statement->bindValue($index + 1, $value, $type);
            }
            $statement->execute();
        } catch (\Throwable $exception) {
            throw new QueryException($sql, $bindings, $exception);
        }
        self::recordQuery([
            'sql' => $sql,
            'bindings' => $bindings,
            'duration' => (microtime(true) - $started) * 1000,
            'connection' => $connection,
            'model' => $model,
            'source' => 'database',
            'rows' => $statement->rowCount(),
        ]);
        return $statement;
    }

    public static function recordCache(string $source, ?string $model = null): void
    {
        self::recordQuery([
            'sql' => null, 'bindings' => [], 'duration' => 0.0,
            'connection' => 'default', 'model' => $model, 'source' => $source, 'rows' => null,
        ]);
    }

    public static function enableQueryLog(bool $enabled = true): void
    {
        self::$logging = $enabled;
    }

    public static function flushQueryLog(): void
    {
        self::$queryLog = [];
    }

    public static function queryLog(): array
    {
        return self::$queryLog;
    }

    public static function listen(callable $listener): void
    {
        self::$listeners[] = $listener;
    }

    public static function transaction(callable $callback, string $connection = 'default', int $attempts = 1): mixed
    {
        $pdo = self::connection($connection);
        $key = $connection . ':' . spl_object_id($pdo);
        $attempt = 0;
        beginning:
        $attempt++;
        $level = self::$transactionLevels[$key] ?? 0;
        $identitySnapshot = ActiveRecord::captureIdentitySnapshot();
        try {
            if ($level === 0) {
                $pdo->beginTransaction();
            } else {
                $pdo->exec('SAVEPOINT active_record_' . $level);
            }
            self::$transactionLevels[$key] = $level + 1;
            $result = $callback();
            self::$transactionLevels[$key]--;
            if ($level === 0) {
                $pdo->commit();
                foreach (self::$afterCommit[$key] ?? [] as $callbackAfterCommit) {
                    $callbackAfterCommit();
                }
                unset(self::$afterCommit[$key]);
            } else {
                $pdo->exec('RELEASE SAVEPOINT active_record_' . $level);
            }
            return $result;
        } catch (\Throwable $exception) {

            ActiveRecord::restoreIdentitySnapshot($identitySnapshot);
            self::$transactionLevels[$key] = $level;
            if ($level === 0 && $pdo->inTransaction()) {
                $pdo->rollBack();
                unset(self::$afterCommit[$key]);
            } elseif ($level > 0) {
                $pdo->exec('ROLLBACK TO SAVEPOINT active_record_' . $level);
            }
            if ($level === 0 && $attempt < $attempts && self::isDeadlock($exception)) {
                usleep(20_000 * $attempt);
                goto beginning;
            }
            throw $exception;
        }
    }

    public static function afterCommit(callable $callback, string $connection = 'default'): void
    {
        $pdo = self::connection($connection);
        $key = $connection . ':' . spl_object_id($pdo);
        if ((self::$transactionLevels[$key] ?? 0) === 0) {
            $callback();
            return;
        }
        self::$afterCommit[$key][] = $callback;
    }

    private static function recordQuery(array $event): void
    {
        $object = (object) $event;
        if (self::$logging) {
            self::$queryLog[] = $event;
        }
        foreach (self::$listeners as $listener) {
            $listener($object);
        }
    }

    private static function isDeadlock(\Throwable $exception): bool
    {
        $previous = $exception instanceof QueryException ? $exception->getPrevious() : $exception;
        return $previous instanceof \PDOException && in_array($previous->errorInfo[1] ?? null, [1205, 1213], true);
    }
}
