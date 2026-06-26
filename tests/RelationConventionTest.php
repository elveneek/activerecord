<?php

class ExplicitMtmProduct extends \Elveneek\ActiveRecord
{
    protected static string $table = 'products';

    public function pivotCategories()
    {
        return $this->belongsToMany(Category::class, 'categories_to_products');
    }
}

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }
    \Elveneek\ActiveRecord::$db = \Elveneek\ActiveRecord::connect();
    \Elveneek\ActiveRecord::$db->exec(file_get_contents(__DIR__ . '/data/mysql.sql'));
    \Elveneek\ActiveRecord::flushIdentityCache();
    \Elveneek\ActiveRecord::flushSchemaCache();

    if (!class_exists('Product')) {
        class Product extends \Elveneek\ActiveRecord {}
    }
    if (!class_exists('Category')) {
        class Category extends \Elveneek\ActiveRecord {}
    }
    if (!class_exists('Categories_to_product')) {
        class Categories_to_product extends \Elveneek\ActiveRecord {}
    }
});

test('automatic relations use direct foreign-key columns only', function () {
    expect(Category::find(1)->products->pluck('id'))->toBe([1, 2, 5])
        ->and(Product::find(1)->category->id)->toBe(1)
        ->and(Product::find(1)->categories)->toBeNull();
});

test('pivot traversal remains explicit through the intermediate model', function () {
    $products = Category::find(1)
        ->_categories_to_products
        ->_products;

    expect($products->pluck('id'))->toBe([1, 2, 4]);
});

test('many-to-many can still be declared explicitly on a model', function () {
    expect(ExplicitMtmProduct::find(2)->pivotCategories->pluck('id'))->toBe([1, 2]);
});


test('inferred relation manager refuses pivot writes', function () {
    expect(fn () => Product::find(1)->category()->attach(2))
        ->toThrow(LogicException::class);
});