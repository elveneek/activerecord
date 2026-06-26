<?php

class ProductionQueryProduct extends \Elveneek\ActiveRecord
{
    protected static string $table = 'products';
}

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }

    $pdo = \Elveneek\ActiveRecord::connect();
    \Elveneek\DB::setConnection($pdo);
    $pdo->exec(file_get_contents(__DIR__ . '/data/mysql.sql'));

    \Elveneek\ActiveRecord::flushIdentityCache();
    \Elveneek\ActiveRecord::flushSchemaCache();
    \Elveneek\DB::flushQueryLog();
});

test('cold findMany preserves the requested id order', function () {
    $ids = ProductionQueryProduct::findMany([5, 2, 4])->pluck('id');

    expect($ids)->toBe([5, 2, 4]);
});

test('findMany with an empty list performs no database query', function () {
    $products = ProductionQueryProduct::findMany([]);

    expect($products->toArray())->toBe([]);

    $databaseQueries = array_filter(
        \Elveneek\DB::queryLog(),
        static fn (array $event) => $event['sql'] !== null,
    );
    expect($databaseQueries)->toBeEmpty();
});

test('chunkById visits each row exactly once despite pre-existing ordering', function () {
    $visited = [];

    ProductionQueryProduct::orderBy('title', 'desc')->chunkById(2, function ($chunk) use (&$visited) {
        foreach ($chunk as $product) {
            $visited[] = $product->id;
        }
    });

    sort($visited);
    expect($visited)->toBe([1, 2, 3, 4, 5]);
});

test('public query builder is immutable', function () {
    $base = \Elveneek\DB::table('products')->where('category_id', 1);
    $narrow = $base->where('id', 2);

    expect($base->bindings())->toBe([1])
        ->and($narrow->bindings())->toBe([1, 2])
        ->and($base->count())->toBe(3)
        ->and($narrow->count())->toBe(1);
});

test('select rejects non-identifier SQL unless selectRaw is used', function () {
    expect(fn () => ProductionQueryProduct::select('title FROM products')->toSql())
        ->toThrow(\Elveneek\Exception\InvalidIdentifierException::class);

    expect(ProductionQueryProduct::selectRaw('UPPER(title) AS upper_title')->firstOrFail()->upper_title)
        ->toBe('FIRST PRODUCT');
});

test('subquery predicates retain binding order and return matching rows', function () {
    $categoryIds = \Elveneek\DB::table('categories')
        ->select('id')
        ->whereLike('title', 'First%');

    $products = ProductionQueryProduct::whereIn('category_id', $categoryIds)
        ->where('id', '>', 1)
        ->orderBy('id');

    expect($products->bindings())->toBe(['First%', 1])
        ->and($products->pluck('id'))->toBe([2, 5])
        ->and($products->queryDependencies())->toBe(['products', 'categories']);
});

test('fromQuery rejects aggregate and primary-key-less model projections', function () {
    $projection = \Elveneek\DB::table('products')->select('title');
    $aggregate = \Elveneek\DB::table('products')->selectRaw('COUNT(*) AS total')->groupBy('category_id');

    expect(fn () => ProductionQueryProduct::fromQuery($projection))
        ->toThrow(\Elveneek\Exception\IncompatibleQueryException::class)
        ->and(fn () => ProductionQueryProduct::fromQuery($aggregate))
        ->toThrow(\Elveneek\Exception\IncompatibleQueryException::class);
});

test('query builder validates identifiers operators and bounds', function () {
    expect(fn () => \Elveneek\DB::table('products')->orderBy('id; DROP TABLE products'))
        ->toThrow(\Elveneek\Exception\InvalidIdentifierException::class)
        ->and(fn () => \Elveneek\DB::table('products')->whereColumn('id', '<=>', 'brand_id'))
        ->toThrow(\Elveneek\Exception\UnsupportedOperatorException::class)
        ->and(fn () => \Elveneek\DB::table('products')->limit(-1))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => \Elveneek\DB::table('products')->offset(-1))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => \Elveneek\DB::table('products')->whereBetween('id', [1]))
        ->toThrow(InvalidArgumentException::class);
});

test('bulk writes invalidate result cache', function () {
    $before = ProductionQueryProduct::where('category_id', 1)->orderBy('id')->remember(60)->pluck('title');

    ProductionQueryProduct::where('category_id', 1)->updateAll(['title' => 'Bulk changed']);

    $after = ProductionQueryProduct::where('category_id', 1)->orderBy('id')->remember(60)->pluck('title');
    expect($before)->not->toBe($after)
        ->and($after)->toBe(['Bulk changed', 'Bulk changed', 'Bulk changed']);
});

test('lock modes compile explicitly', function () {
    expect(ProductionQueryProduct::where('id', 1)->lockForUpdate()->toSql())->toEndWith('FOR UPDATE')
        ->and(ProductionQueryProduct::where('id', 1)->sharedLock()->toSql())->toEndWith('LOCK IN SHARE MODE');
});
