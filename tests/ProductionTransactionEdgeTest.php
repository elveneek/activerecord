<?php

class ProductionTransactionProduct extends \Elveneek\ActiveRecord
{
    protected static string $table = 'products';
    protected static ?string $versionColumn = 'lock_version';
}

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }

    $pdo = \Elveneek\ActiveRecord::connect();
    \Elveneek\DB::setConnection($pdo);
    $pdo->exec(file_get_contents(__DIR__ . '/data/mysql.sql'));
    $pdo->exec('ALTER TABLE products ENGINE=InnoDB');
    $pdo->exec('ALTER TABLE products ADD lock_version INT NOT NULL DEFAULT 0');
    $pdo->exec('ALTER TABLE products ADD sku VARCHAR(64) NULL, ADD UNIQUE KEY products_sku_unique (sku)');

    \Elveneek\ActiveRecord::flushIdentityCache();
    \Elveneek\ActiveRecord::flushSchemaCache();
    \Elveneek\DB::flushQueryLog();
});

test('a rolled back transaction cannot leak rows through result cache', function () {
    try {
        \Elveneek\DB::transaction(function () {
            $product = ProductionTransactionProduct::findOrFail(1);
            $product->title = 'Uncommitted title';
            $product->save();

            expect(
                ProductionTransactionProduct::where('id', 1)->remember(60)->firstOrFail()->title
            )->toBe('Uncommitted title');

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('rollback');
    }

    $cachedTitle = ProductionTransactionProduct::where('id', 1)
        ->remember(60)
        ->firstOrFail()
        ->title;

    ProductionTransactionProduct::flushIdentityCache();
    $databaseTitle = ProductionTransactionProduct::findOrFail(1)->title;

    expect($databaseTitle)->toBe('First product')
        ->and($cachedTitle)->toBe($databaseTitle);
});

test('afterCommit registered in a rolled back savepoint is discarded', function () {
    $calls = 0;

    \Elveneek\DB::transaction(function () use (&$calls) {
        try {
            \Elveneek\DB::transaction(function () use (&$calls) {
                \Elveneek\DB::afterCommit(function () use (&$calls) {
                    $calls++;
                });
                throw new RuntimeException('rollback inner transaction');
            });
        } catch (RuntimeException) {
            // The outer transaction deliberately continues and commits.
        }
    });

    expect($calls)->toBe(0);
});

test('afterCommit from a successful nested transaction runs once after outer commit', function () {
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

test('afterCommit outside a transaction runs immediately', function () {
    $calls = 0;

    \Elveneek\DB::afterCommit(function () use (&$calls) {
        $calls++;
    });

    expect($calls)->toBe(1);
});

test('saveAll rolls back both database rows and in-memory states when a later row fails', function () {
    \Elveneek\ActiveRecord::$db->exec("UPDATE products SET sku = 'already-taken' WHERE id = 1");

    $batch = ProductionTransactionProduct::create()
        ->addRow(['title' => 'First batch row', 'sku' => 'batch-first'])
        ->addRow(['title' => 'Second batch row', 'sku' => 'already-taken']);

    expect(fn () => $batch->saveAll())
        ->toThrow(\Elveneek\Exception\QueryException::class);

    expect(ProductionTransactionProduct::where('sku', 'batch-first')->withoutCache()->count())->toBe(0)
        ->and($batch[0]->isNew())->toBeTrue()
        ->and($batch[0]->id)->toBeNull()
        ->and($batch[0]->isDirty('title'))->toBeTrue()
        ->and($batch[1]->isNew())->toBeTrue()
        ->and($batch->lastSaveErrors())->not->toBeEmpty();
});

test('optimistic lock detects a write performed by a second connection', function () {
    $product = ProductionTransactionProduct::findOrFail(1);
    $otherConnection = \Elveneek\ActiveRecord::connect();
    $otherConnection->exec('UPDATE products SET title = "Concurrent", lock_version = lock_version + 1 WHERE id = 1');

    $product->title = 'Stale local value';

    expect(fn () => $product->save())
        ->toThrow(\Elveneek\Exception\StaleModelException::class);
});
