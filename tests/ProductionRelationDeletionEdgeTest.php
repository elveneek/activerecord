<?php

class ProductionRelationProduct extends \Elveneek\ActiveRecord
{
    protected static string $table = 'products';
}

class ProductionRelationCategory extends \Elveneek\ActiveRecord
{
    protected static string $table = 'categories';

    public function pivotProducts()
    {
        return $this->belongsToMany(ProductionRelationProduct::class, 'categories_to_products');
    }
}

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }

    $pdo = \Elveneek\ActiveRecord::connect();
    \Elveneek\DB::setConnection($pdo);
    $pdo->exec(file_get_contents(__DIR__ . '/data/mysql.sql'));
    $pdo->exec('ALTER TABLE products ENGINE=InnoDB');
    $pdo->exec('ALTER TABLE categories_to_products ENGINE=InnoDB');
    $pdo->exec('ALTER TABLE categories_to_products ADD UNIQUE KEY category_product_unique (category_id, product_id)');

    \Elveneek\ActiveRecord::flushIdentityCache();
    \Elveneek\ActiveRecord::flushSchemaCache();
});

test('delete rejects an unbound result containing more than one row', function () {
    $products = ProductionRelationProduct::where('category_id', 1)->orderBy('id');

    expect(fn () => $products->delete())
        ->toThrow(\Elveneek\Exception\AmbiguousWriteException::class);

    expect(ProductionRelationProduct::where('category_id', 1)->count())->toBe(3);
});

test('delete on a row-bound model removes only that row and invalidates lookup', function () {
    $product = ProductionRelationProduct::where('category_id', 1)->orderBy('id')->firstOrFail();
    $deletedId = $product->id;

    $product->delete();

    expect($product->affectedRows())->toBe(1)
        ->and(ProductionRelationProduct::findOrNull($deletedId))->toBeNull()
        ->and(ProductionRelationProduct::where('category_id', 1)->count())->toBe(2);
});

test('detach with an empty id list is a no-op', function () {
    $category = ProductionRelationCategory::findOrFail(1);
    $before = $category->pivotProducts()->get()->orderBy('products.id')->pluck('id');

    $category->pivotProducts()->detach([]);

    $after = $category->pivotProducts()->get()->orderBy('products.id')->pluck('id');
    expect($after)->toBe($before);
});

test('attach detach and sync produce the requested pivot set', function () {
    $category = ProductionRelationCategory::findOrFail(1);

    $category->pivotProducts()->attach(3, ['sort' => 50]);
    expect($category->pivotProducts()->get()->orderBy('products.id')->pluck('id'))->toBe([1, 2, 3, 4]);

    $category->pivotProducts()->detach([1, 4]);
    expect($category->pivotProducts()->get()->orderBy('products.id')->pluck('id'))->toBe([2, 3]);

    $category->pivotProducts()->sync([2, 5]);
    expect($category->pivotProducts()->get()->orderBy('products.id')->pluck('id'))->toBe([2, 5]);
});

test('sync is atomic when inserting the replacement set fails', function () {
    $category = ProductionRelationCategory::findOrFail(1);
    $original = $category->pivotProducts()->get()->orderBy('products.id')->pluck('id');

    expect(fn () => $category->pivotProducts()->sync([2, 2]))
        ->toThrow(\Elveneek\Exception\QueryException::class);

    $actual = $category->pivotProducts()->get()->orderBy('products.id')->pluck('id');
    expect($actual)->toBe($original);
});

test('pivot writes invalidate an explicitly cached relation query', function () {
    $category = ProductionRelationCategory::findOrFail(1);

    $before = $category->pivotProducts()->get()
        ->orderBy('products.id')
        ->remember(60)
        ->pluck('id');

    $category->pivotProducts()->attach(3);

    $after = ProductionRelationCategory::findOrFail(1)
        ->pivotProducts()->get()
        ->orderBy('products.id')
        ->remember(60)
        ->pluck('id');

    expect($before)->toBe([1, 2, 4])
        ->and($after)->toBe([1, 2, 3, 4]);
});
