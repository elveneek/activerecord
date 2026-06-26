<?php

if (!class_exists('Product')) {
    class Product extends \Elveneek\ActiveRecord {}
}
if (!class_exists('Category')) {
    class Category extends \Elveneek\ActiveRecord {}
}
if (!class_exists('Brand')) {
    class Brand extends \Elveneek\ActiveRecord {}
}
if (!class_exists('Categories_to_product')) {
    class Categories_to_product extends \Elveneek\ActiveRecord
    {
        protected static string $table = 'categories_to_products';
    }
}

class RelationsProduct extends \Elveneek\ActiveRecord
{
    protected static string $table = 'products';

    public function category()
    {
        return $this->belongsTo(RelationsCategory::class, 'category_id');
    }

    public function brand()
    {
        return $this->belongsTo(RelationsBrand::class, 'brand_id');
    }
}

class RelationsBrand extends \Elveneek\ActiveRecord
{
    protected static string $table = 'brands';
}

class RelationsCategory extends \Elveneek\ActiveRecord
{
    protected static string $table = 'categories';

    public function manyProducts()
    {
        return $this->hasMany(RelationsProduct::class, 'category_id');
    }

    public function pivotProducts()
    {
        return $this->belongsToMany(RelationsProduct::class, 'categories_to_products');
    }
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

test('conventional belongsTo resolves the parent through the foreign key', function () {
    expect(Product::findOrFail(3)->category->id)->toBe(2)
        ->and(Product::findOrFail(3)->category->title)->toBe('Second category');
});

test('conventional belongsTo returns an empty stub when the foreign key is null', function () {
    expect(Product::findOrFail(5)->brand->isEmpty())->toBeTrue();
});

test('conventional hasMany returns every child carrying the local key', function () {
    $category = Category::findOrFail(1);

    expect($category->products->count())->toBe(3)
        ->and($category->products->pluck('id'))->toBe([1, 2, 5]);
});

test('conventional hasMany returns an empty collection when nothing matches', function () {
    \Elveneek\ActiveRecord::$db->exec('INSERT INTO categories (id, title) VALUES (3, "Empty category")');
    \Elveneek\ActiveRecord::flushSchemaCache();

    expect(Category::findOrFail(3)->products->isEmpty())->toBeTrue();
});

test('explicit belongsTo resolves through the declared relation method', function () {
    expect(RelationsProduct::findOrFail(2)->category->id)->toBe(1);
});

test('explicit hasMany returns children via the declared relation', function () {
    expect(RelationsCategory::findOrFail(1)->manyProducts->count())->toBe(3)
        ->and(RelationsCategory::findOrFail(2)->manyProducts->pluck('id'))->toBe([3, 4]);
});

test('explicit belongsToMany traverses the pivot and deduplicates rows', function () {
    expect(RelationsCategory::findOrFail(1)->pivotProducts()->get()->orderBy('products.id')->pluck('id'))->toBe([1, 2, 4]);
});

test('a belongsToMany query supports further where narrowing after get', function () {
    $ids = RelationsCategory::findOrFail(2)->pivotProducts()->get()->where('brand_id', 3)->pluck('id');

    expect($ids)->toBe([3]);
});

test('belongsToMany get is distinct by default', function () {
    expect(RelationsCategory::findOrFail(1)->pivotProducts()->get()->toSql())->toContain('DISTINCT');
});

test('associating a belongsTo writes the foreign key in memory', function () {
    $product = RelationsProduct::findOrFail(1);

    $product->category()->associate(RelationsCategory::findOrFail(2));

    expect($product->category_id)->toBe(2);
});

test('dissociating a belongsTo nulls the foreign key', function () {
    $product = RelationsProduct::findOrFail(1);

    $product->category()->dissociate();

    expect($product->category_id)->toBeNull();
});

test('attach detach and sync maintain the pivot set', function () {
    $category = RelationsCategory::findOrFail(1);

    $category->pivotProducts()->attach(5);
    expect($category->pivotProducts()->get()->orderBy('products.id')->pluck('id'))->toBe([1, 2, 4, 5]);

    $category->pivotProducts()->detach([1, 5]);
    expect($category->pivotProducts()->get()->orderBy('products.id')->pluck('id'))->toBe([2, 4]);

    $category->pivotProducts()->sync([1, 2]);
    expect($category->pivotProducts()->get()->orderBy('products.id')->pluck('id'))->toBe([1, 2]);
});

test('attach accepts additional pivot attributes', function () {
    RelationsCategory::findOrFail(1)->pivotProducts()->attach(3, ['sort' => 77]);

    $row = \Elveneek\DB::connection()->query(
        'SELECT sort FROM categories_to_products WHERE category_id = 1 AND product_id = 3'
    )->fetchColumn();

    expect((int) $row)->toBe(77);
});

test('detach with no arguments removes every pivot row for the owner', function () {
    RelationsCategory::findOrFail(1)->pivotProducts()->detach();

    expect(RelationsCategory::findOrFail(1)->pivotProducts()->get()->isEmpty())->toBeTrue();
});

test('whereHas filters parents that own matching children', function () {
    expect(Category::whereHas('products')->count())->toBe(2)
        ->and(Category::whereHas('products', fn ($q) => $q->where('brand_id', 3))->count())->toBe(1);
});

test('whereDoesntHave excludes parents with matching children', function () {
    \Elveneek\ActiveRecord::$db->exec('INSERT INTO categories (id, title) VALUES (9, "Lonely")');
    \Elveneek\ActiveRecord::flushSchemaCache();

    expect(Category::whereDoesntHave('products')->count())->toBe(1);
});

test('has and doesntHave are convenience wrappers around whereHas', function () {
    expect(Category::has('products')->count())->toBe(2)
        ->and(Category::doesntHave('products')->count())->toBe(0);
});

test('join by relation exposes aliased columns from the related table', function () {
    $product = Product::join('brand')
        ->select('products.*')
        ->addSelect('brand.title AS brand_title')
        ->where('products.id', 2)
        ->firstOrFail();

    expect($product->brand_title)->toBe('Samsung');
});

test('legacy underscore traversal walks through the pivot model', function () {
    expect(Category::findOrFail(1)->_categories_to_products->_products->pluck('id'))->toBe([1, 2, 4]);
});

test('related returns null for an unresolvable relation name', function () {
    expect(Product::findOrFail(1)->related('nonexistent_table'))->toBeNull();
});

test('plus merges another result set by primary key deduplication', function () {
    expect(Product::where('id', '<=', 2)->plus(Product::where('id', 2))->pluck('id'))->toBe([1, 2]);
});

test('plus accepts a literal id list', function () {
    expect(Product::where('id', 1)->plus([2, 3])->pluck('id'))->toBe([1, 2, 3]);
});

test('eager loading with populates the relation without n plus one queries', function () {
    \Elveneek\DB::flushQueryLog();

    $products = Product::orderBy('id')->with('category')->load();
    foreach ($products as $product) {
        $product->category->title;
    }

    $sqlEvents = array_values(array_filter(\Elveneek\DB::queryLog(), fn ($e) => $e['sql'] !== null));
    expect($sqlEvents)->toHaveCount(2)
        ->and($products[0]->category->title)->toBe('First category');
});

test('dotted eager loading walks nested relations', function () {
    \Elveneek\DB::flushQueryLog();

    $categories = Category::orderBy('id')->with('products.category')->load();

    $sqlEvents = array_values(array_filter(\Elveneek\DB::queryLog(), fn ($e) => $e['sql'] !== null));
    expect(count($sqlEvents))->toBeLessThanOrEqual(3)
        ->and($categories[0]->products[0]->category->id)->toBe(1);
});

test('flushing the identity cache lets relations observe external writes', function () {
    $first = Category::findOrFail(1)->products->pluck('id');

    \Elveneek\DB::connection()->exec('UPDATE products SET category_id = 2 WHERE id = 5');
    \Elveneek\ActiveRecord::flushIdentityCache();

    $second = Category::findOrFail(1)->products->pluck('id');

    expect($first)->toBe([1, 2, 5])
        ->and($second)->toBe([1, 2]);
});
