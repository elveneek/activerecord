<?php

class TxAccount extends \Elveneek\ActiveRecord
{
    protected static string $table = 'tx_accounts';
    protected static array $casts = ['id' => 'int', 'balance' => 'int'];
}

function deadlockException(int $code): \Elveneek\Exception\QueryException
{
    $pdo = new \PDOException('SQLSTATE deadlock');
    $pdo->errorInfo = ['HY000', $code, 'Deadlock found'];
    return new \Elveneek\Exception\QueryException('UPDATE tx_accounts SET balance = balance + 1', [], $pdo);
}

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }
    $pdo = \Elveneek\ActiveRecord::connect();
    \Elveneek\DB::setConnection($pdo);
    $pdo->exec('DROP TABLE IF EXISTS tx_accounts');
    $pdo->exec(
        'CREATE TABLE tx_accounts ('
        . 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
        . 'name VARCHAR(64) NOT NULL, '
        . 'balance INT NOT NULL DEFAULT 0, '
        . 'UNIQUE KEY tx_accounts_name_unique (name)'
        . ') ENGINE=InnoDB'
    );
    TxAccount::insertAll([
        ['name' => 'Alice', 'balance' => 100],
        ['name' => 'Bob', 'balance' => 50],
    ]);
    \Elveneek\ActiveRecord::flushIdentityCache();
    \Elveneek\ActiveRecord::flushSchemaCache();
});

test('a committed transaction persists every change', function () {
    \Elveneek\DB::transaction(function () {
        TxAccount::where('name', 'Alice')->updateAll(['balance' => 90]);
        TxAccount::where('name', 'Bob')->updateAll(['balance' => 60]);
    });

    TxAccount::flushIdentityCache();
    expect(TxAccount::where('name', 'Alice')->value('balance'))->toBe(90)
        ->and(TxAccount::where('name', 'Bob')->value('balance'))->toBe(60);
});

test('a rolled back transaction discards every change', function () {
    try {
        \Elveneek\DB::transaction(function () {
            TxAccount::where('name', 'Alice')->updateAll(['balance' => 0]);
            throw new RuntimeException('rollback please');
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('rollback please');
    }

    TxAccount::flushIdentityCache();
    expect(TxAccount::where('name', 'Alice')->value('balance'))->toBe(100);
});

test('transaction returns the callback value', function () {
    $result = \Elveneek\DB::transaction(function () {
        return 'committed value';
    });

    expect($result)->toBe('committed value');
});

test('nested transactions use savepoints and an inner rollback keeps the outer alive', function () {
    \Elveneek\DB::transaction(function () {
        TxAccount::where('name', 'Alice')->updateAll(['balance' => 80]);

        try {
            \Elveneek\DB::transaction(function () {
                TxAccount::where('name', 'Bob')->updateAll(['balance' => 999]);
                throw new RuntimeException('inner rollback');
            });
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('inner rollback');
        }

        TxAccount::where('name', 'Bob')->updateAll(['balance' => 70]);
    });

    TxAccount::flushIdentityCache();
    expect(TxAccount::where('name', 'Alice')->value('balance'))->toBe(80)
        ->and(TxAccount::where('name', 'Bob')->value('balance'))->toBe(70);
});

test('an exception in the outer transaction rolls back inner committed savepoints too', function () {
    try {
        \Elveneek\DB::transaction(function () {
            \Elveneek\DB::transaction(function () {
                TxAccount::where('name', 'Alice')->updateAll(['balance' => 1]);
            });
            throw new RuntimeException('outer rollback');
        });
    } catch (RuntimeException) {
    }

    TxAccount::flushIdentityCache();
    expect(TxAccount::where('name', 'Alice')->value('balance'))->toBe(100);
});

test('afterCommit runs immediately when no transaction is active', function () {
    $ran = false;
    \Elveneek\DB::afterCommit(function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeTrue();
});

test('afterCommit fires once when the outer transaction commits', function () {
    $calls = 0;

    \Elveneek\DB::transaction(function () use (&$calls) {
        \Elveneek\DB::transaction(function () use (&$calls) {
            \Elveneek\DB::afterCommit(function () use (&$calls) {
                $calls++;
            });
            expect($calls)->toBe(0);
        });
        expect($calls)->toBe(0);
    });

    expect($calls)->toBe(1);
});

test('afterCommit registered in a rolled back outer transaction never fires', function () {
    $calls = 0;

    try {
        \Elveneek\DB::transaction(function () use (&$calls) {
            \Elveneek\DB::afterCommit(function () use (&$calls) {
                $calls++;
            });
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect($calls)->toBe(0);
});

test('afterCommit bubbles to the parent savepoint when an inner savepoint commits', function () {
    $calls = 0;

    \Elveneek\DB::transaction(function () use (&$calls) {
        \Elveneek\DB::transaction(function () use (&$calls) {
            \Elveneek\DB::afterCommit(function () use (&$calls) {
                $calls++;
            });
        });
        expect($calls)->toBe(0);
    });

    expect($calls)->toBe(1);
});

test('saveAll runs inside a transaction and rolls back atomically on failure', function () {
    \Elveneek\ActiveRecord::$db->exec("INSERT INTO tx_accounts (name, balance) VALUES ('unique', 0)");

    $batch = TxAccount::create()
        ->addRow(['name' => 'first', 'balance' => 1])
        ->addRow(['name' => 'unique', 'balance' => 2]);

    expect(fn () => $batch->saveAll())->toThrow(\Elveneek\Exception\QueryException::class);

    expect(TxAccount::where('name', 'first')->withoutCache()->count())->toBe(0);
});

test('isDeadlock recognises mysql deadlock and lock wait timeout codes', function () {
    $reflection = new ReflectionMethod(\Elveneek\DB::class, 'isDeadlock');
    $reflection->setAccessible(true);

    $deadlock = deadlockException(1213);
    $lockWait = deadlockException(1205);
    $other = deadlockException(1062);

    expect($reflection->invoke(null, $deadlock))->toBeTrue()
        ->and($reflection->invoke(null, $lockWait))->toBeTrue()
        ->and($reflection->invoke(null, $other))->toBeFalse();
});

test('transaction attempts can retry on a deadlock', function () {
    $attempts = 0;

    $result = \Elveneek\DB::transaction(function () use (&$attempts) {
        $attempts++;
        if ($attempts < 2) {
            throw deadlockException(1213);
        }
        return 'recovered';
    }, 'default', 3);

    expect($result)->toBe('recovered')
        ->and($attempts)->toBe(2);
});

test('transaction exhausts attempts and rethrows after retries', function () {
    $attempts = 0;

    expect(function () use (&$attempts) {
        \Elveneek\DB::transaction(function () use (&$attempts) {
            $attempts++;
            throw deadlockException(1213);
        }, 'default', 2);
    })->toThrow(\Elveneek\Exception\QueryException::class);

    expect($attempts)->toBe(2);
});

test('a non deadlock exception is never retried', function () {
    $attempts = 0;

    expect(function () use (&$attempts) {
        \Elveneek\DB::transaction(function () use (&$attempts) {
            $attempts++;
            throw new RuntimeException('not a deadlock');
        }, 'default', 5);
    })->toThrow(RuntimeException::class);

    expect($attempts)->toBe(1);
});

test('query logging records executed statements', function () {
    \Elveneek\DB::flushQueryLog();
    \Elveneek\DB::enableQueryLog(true);

    TxAccount::where('name', 'Alice')->first();

    $log = \Elveneek\DB::queryLog();
    expect($log)->not->toBeEmpty()
        ->and($log[0]['sql'])->toContain('SELECT');
});

test('disabling the query log stops recording', function () {
    \Elveneek\DB::enableQueryLog(false);
    \Elveneek\DB::flushQueryLog();

    TxAccount::all()->count();

    expect(\Elveneek\DB::queryLog())->toBeEmpty();
    \Elveneek\DB::enableQueryLog(true);
});

test('listeners receive every executed query event', function () {
    $received = [];
    \Elveneek\DB::listen(function ($event) use (&$received) {
        $received[] = $event;
    });

    TxAccount::all()->count();

    expect($received)->not->toBeEmpty()
        ->and($received[0])->toHaveKey('sql');
});

test('a runtime snapshot is restored when a transaction rolls back', function () {
    try {
        \Elveneek\DB::transaction(function () {
            TxAccount::where('name', 'Alice')->updateAll(['balance' => 1]);
            throw new RuntimeException('snap');
        });
    } catch (RuntimeException) {
    }

    $cachedBalance = TxAccount::where('name', 'Alice')->remember(60)->value('balance');
    expect($cachedBalance)->toBe(100);
});

test('DB raw and now return expression instances', function () {
    expect(\Elveneek\DB::raw('1 + 1'))->toBeInstanceOf(\Elveneek\Query\Expression::class)
        ->and(\Elveneek\DB::now())->toBeInstanceOf(\Elveneek\Query\Expression::class);
});

test('DB table returns a fresh query builder for the table', function () {
    $query = \Elveneek\DB::table('tx_accounts')->where('balance', '>', 0);

    expect($query->count())->toBe(2)
        ->and($query->firstRow()->name)->toBe('Alice');
});

test('a connection resolver is re-invoked on every connection lookup', function () {
    $calls = 0;
    $pdo = \Elveneek\DB::connection();

    \Elveneek\DB::setConnectionResolver(function () use ($pdo, &$calls) {
        $calls++;
        return $pdo;
    });

    \Elveneek\DB::connection();
    \Elveneek\DB::connection();

    expect($calls)->toBe(2);
    \Elveneek\DB::clearConnectionResolver();
});
