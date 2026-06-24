<?php

class ResultCachedProduct extends \Elveneek\ActiveRecord
{
    protected static string $table = 'products';
}

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }
    \Elveneek\ActiveRecord::$db = \Elveneek\ActiveRecord::connect();
    \Elveneek\ActiveRecord::$db->exec(file_get_contents(__DIR__ . '/data/mysql.sql'));
    \Elveneek\ActiveRecord::flushIdentityCache();
    \Elveneek\ActiveRecord::flushSchemaCache();
    \Elveneek\DB::flushQueryLog();
});

test('explicit result cache hits and is invalidated by writes', function () {
    $first = ResultCachedProduct::where('id', '<=', 2)->orderBy('id')->remember(60)->toArray();
    $second = ResultCachedProduct::where('id', '<=', 2)->orderBy('id')->remember(60)->toArray();

    $sqlEvents = array_values(array_filter(\Elveneek\DB::queryLog(), fn ($event) => $event['sql'] !== null));
    expect($second)->toBe($first)
        ->and($sqlEvents)->toHaveCount(1);

    $product = ResultCachedProduct::findOrFail(1);
    $product->title = 'Cache invalidated';
    $product->save();
    \Elveneek\DB::flushQueryLog();

    $fresh = ResultCachedProduct::where('id', '<=', 2)->orderBy('id')->remember(60)->toArray();
    $sqlEvents = array_values(array_filter(\Elveneek\DB::queryLog(), fn ($event) => $event['sql'] !== null));
    expect($fresh[0]['title'])->toBe('Cache invalidated')
        ->and($sqlEvents)->toHaveCount(1);
});

