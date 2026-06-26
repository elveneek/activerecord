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
    private static array $connectionResolvers = [];

    public static function setConnection(\PDO $connection, string $name = 'default'): void
    {
        unset(self::$connectionResolvers[$name]);
        self::storeConnection($connection, $name);
    }

    public static function setConnectionResolver(callable $resolver, string $name = 'default'): void
    {
        self::$connectionResolvers[$name] = $resolver;
    }

    public static function clearConnectionResolver(string $name = 'default'): void
    {
        unset(self::$connectionResolvers[$name]);
    }

    public static function replaceConnection(\PDO $connection, string $name = 'default'): void
    {
        self::storeConnection($connection, $name);
    }

    private static function storeConnection(\PDO $connection, string $name): void
    {
        self::$connections[$name] = $connection;
        if ($name === 'default') {
            ActiveRecord::$db = $connection;
        }
    }

    public static function connection(string $name = 'default'): \PDO
    {
        if (isset(self::$connectionResolvers[$name])) {
            $connection = (self::$connectionResolvers[$name])();
            if (!$connection instanceof \PDO) {
                throw new \RuntimeException("Database connection resolver '{$name}' did not return a PDO instance.");
            }
            self::storeConnection($connection, $name);
            return $connection;
        }

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
        $depth = $level + 1;
        $runtimeSnapshot = ActiveRecord::captureRuntimeSnapshot();
        $databaseCommitted = false;
        try {
            if ($level === 0) {
                $pdo->beginTransaction();
            } else {
                $pdo->exec('SAVEPOINT active_record_' . $level);
            }
            self::$transactionLevels[$key] = $depth;
            $result = $callback();
            self::$transactionLevels[$key] = $level;
            if ($level === 0) {
                $pdo->commit();
                $databaseCommitted = true;
                $callbacks = self::$afterCommit[$key][$depth] ?? [];
                unset(self::$afterCommit[$key]);
                foreach ($callbacks as $callbackAfterCommit) {
                    $callbackAfterCommit();
                }
            } else {
                $pdo->exec('RELEASE SAVEPOINT active_record_' . $level);
                $callbacks = self::$afterCommit[$key][$depth] ?? [];
                unset(self::$afterCommit[$key][$depth]);
                if ($callbacks !== []) {
                    self::$afterCommit[$key][$level] = array_merge(self::$afterCommit[$key][$level] ?? [], $callbacks);
                }
            }
            return $result;
        } catch (\Throwable $exception) {
            if ($databaseCommitted) {
                throw $exception;
            }
            ActiveRecord::restoreRuntimeSnapshot($runtimeSnapshot);
            self::$transactionLevels[$key] = $level;
            foreach (array_keys(self::$afterCommit[$key] ?? []) as $callbackDepth) {
                if ($callbackDepth >= $depth) {
                    unset(self::$afterCommit[$key][$callbackDepth]);
                }
            }
            if ((self::$afterCommit[$key] ?? []) === []) {
                unset(self::$afterCommit[$key]);
            }
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
        $depth = self::$transactionLevels[$key] ?? 0;
        if ($depth === 0) {
            $callback();
            return;
        }
        self::$afterCommit[$key][$depth][] = $callback;
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
