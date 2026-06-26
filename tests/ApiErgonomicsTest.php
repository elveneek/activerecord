<?php

class ErgonomicProduct extends \Elveneek\ActiveRecord
{
    protected static string $table = 'products';
    protected static string $defaultOrder = 'sort';
    protected static array $casts = ['id' => 'int'];
}

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }
    $pdo = \Elveneek\ActiveRecord::connect();
    \Elveneek\DB::setConnection($pdo);
    $pdo->exec(file_get_contents(__DIR__ . '/data/mysql.sql'));
    $pdo->exec("UPDATE products SET sort = id");
    \Elveneek\ActiveRecord::flushIdentityCache();
    \Elveneek\ActiveRecord::flushSchemaCache();
});

test('a default order is applied to every new query', function () {
    expect(ErgonomicProduct::all()->toSql())->toContain('ORDER BY `sort`');
});

test('orderBy with an empty string resets the order entirely', function () {
    expect(ErgonomicProduct::orderBy('')->toSql())->not->toContain('ORDER BY');
});

test('withoutOrder is available directly on the model and drops the order', function () {
    expect(ErgonomicProduct::all()->withoutOrder()->toSql())->not->toContain('ORDER BY');
});

test('withoutLimitOffset is available directly on the model', function () {
    $sql = ErgonomicProduct::all()->limit(5)->offset(2)->withoutLimitOffset()->toSql();

    expect($sql)->not->toContain('LIMIT')->not->toContain('OFFSET');
});

test('orderBy empty keeps a previously chained explicit order removed too', function () {
    $sql = ErgonomicProduct::orderBy('title')->orderBy('')->toSql();

    expect($sql)->not->toContain('ORDER BY');
});

test('a real query without order returns rows in primary key order', function () {
    $ids = ErgonomicProduct::withoutOrder()->orderBy('id')->pluck('id');

    expect($ids)->toBe([1, 2, 3, 4, 5]);
});

test('select star loads every column', function () {
    $product = ErgonomicProduct::select('*')->where('id', 1)->first();

    expect($product->id)->toBe(1)
        ->and($product->title)->toBe('First product')
        ->and($product->category_id)->toBe(1);
});

test('select with a distinct prefix compiles to SELECT DISTINCT', function () {
    $query = ErgonomicProduct::select('distinct category_id');

    expect($query->toSql())->toContain('SELECT DISTINCT `category_id`')
        ->and($query->orderBy('category_id')->pluck('category_id'))->toBe([1, 2]);
});

test('select distinct works for multiple columns', function () {
    $query = ErgonomicProduct::select('distinct category_id', 'brand_id');

    expect($query->toSql())->toStartWith('SELECT DISTINCT `category_id`, `brand_id`');
});

test('count is usable as a terminal method on the query', function () {
    expect(ErgonomicProduct::all()->count())->toBe(5)
        ->and(ErgonomicProduct::where('category_id', 1)->count())->toBe(3)
        ->and(ErgonomicProduct::where('id', 999)->count())->toBe(0);
});

test('chunkById and eachById are usable as static factories', function () {
    $chunkSizes = [];
    ErgonomicProduct::chunkById(2, function ($chunk) use (&$chunkSizes) {
        $chunkSizes[] = $chunk->count();
    });

    $seen = 0;
    ErgonomicProduct::eachById(10, function () use (&$seen) {
        $seen++;
    });

    expect($chunkSizes)->toBe([2, 2, 1])
        ->and($seen)->toBe(5);
});

test('findOrFail respects a chained select and only loads those columns', function () {
    $product = ErgonomicProduct::select('id')->findOrFail(1);

    expect($product->id)->toBe(1)
        ->and($product->title)->toBeNull();
});

test('findOrNull respects the chain and returns null when missing', function () {
    expect(ErgonomicProduct::select('id')->findOrNull(1)->id)->toBe(1)
        ->and(ErgonomicProduct::findOrNull(999))->toBeNull();
});

test('findOrFail without a chain loads all columns', function () {
    expect(ErgonomicProduct::findOrFail(1)->title)->toBe('First product');
});

test('find respects the chain too via the magic method', function () {
    $product = ErgonomicProduct::select('id', 'title')->find(2)->first();

    expect($product->title)->toBe('Second product')
        ->and($product->category_id)->toBeNull();
});

test('firstOrCreate respects a chained scope', function () {
    $existing = ErgonomicProduct::where('category_id', 1)->firstOrCreate(['title' => 'First product']);

    expect($existing->id)->toBe(1)
        ->and(ErgonomicProduct::all()->count())->toBe(5);
});

test('updateOrCreate on a chained scope updates the matched row', function () {
    ErgonomicProduct::where('category_id', 1)->updateOrCreate(
        ['title' => 'First product'],
        ['type' => 'refreshed'],
    );

    \Elveneek\ActiveRecord::flushIdentityCache();
    expect(ErgonomicProduct::find(1)->type)->toBe('refreshed')
        ->and(ErgonomicProduct::all()->count())->toBe(5);
});
