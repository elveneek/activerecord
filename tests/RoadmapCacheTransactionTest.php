<?php

class TransactionalProduct extends \Elveneek\ActiveRecord
{
    protected static string $table = 'products';
    protected static ?string $versionColumn = 'lock_version';
}

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }
    \Elveneek\ActiveRecord::$db = \Elveneek\ActiveRecord::connect();
    \Elveneek\ActiveRecord::$db->exec(file_get_contents(__DIR__ . '/data/mysql.sql'));
    \Elveneek\ActiveRecord::$db->exec('ALTER TABLE products ENGINE=InnoDB');
    \Elveneek\ActiveRecord::$db->exec('ALTER TABLE products ADD lock_version INT NOT NULL DEFAULT 0');
    \Elveneek\ActiveRecord::flushIdentityCache();
    \Elveneek\ActiveRecord::flushSchemaCache();
    \Elveneek\DB::flushQueryLog();
});

test('findMany asks the database only for identity-map misses', function () {
    TransactionalProduct::find(1)->title;
    TransactionalProduct::find(2)->title;
    \Elveneek\DB::flushQueryLog();

    expect(TransactionalProduct::findMany([1, 2, 3, 4])->pluck('id'))->toBe([1, 2, 3, 4]);
    $queries = array_values(array_filter(\Elveneek\DB::queryLog(), fn ($event) => $event['sql'] !== null));

    expect($queries)->toHaveCount(1)
        ->and($queries[0]['bindings'])->toBe([3, 4]);
});

test('missing primary-key lookup is negatively cached', function () {
    expect(TransactionalProduct::find(999)->isEmpty())->toBeTrue();
    expect(TransactionalProduct::find(999)->isEmpty())->toBeTrue();

    $queries = array_values(array_filter(\Elveneek\DB::queryLog(), fn ($event) => $event['sql'] !== null));
    expect($queries)->toHaveCount(1);
});

test('transaction rollback restores database and canonical record state', function () {
    $product = TransactionalProduct::findOrFail(1);

    try {
        \Elveneek\DB::transaction(function () use ($product) {
            $product->title = 'Rolled back';
            $product->save();
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect($product->title)->toBe('First product');
    TransactionalProduct::flushIdentityCache();
    expect(TransactionalProduct::find(1)->title)->toBe('First product');
});

test('optimistic lock detects a stale update', function () {
    $product = TransactionalProduct::findOrFail(1);
    \Elveneek\ActiveRecord::$db->exec('UPDATE products SET lock_version = 2 WHERE id = 1');
    $product->title = 'Stale write';

    expect(fn () => $product->save())->toThrow(\Elveneek\Exception\StaleModelException::class);
});

