<?php

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }
    \Elveneek\ActiveRecord::$db = \Elveneek\ActiveRecord::connect();
    \Elveneek\ActiveRecord::$db->exec(file_get_contents(__DIR__ . '/data/mysql.sql'));
    \Elveneek\ActiveRecord::flushIdentityCache();
    \Elveneek\ActiveRecord::flushSchemaCache();
    \Elveneek\DB::flushQueryLog();

    if (!class_exists('Product')) {
        class Product extends \Elveneek\ActiveRecord {}
    }
    if (!class_exists('Category')) {
        class Category extends \Elveneek\ActiveRecord {}
    }
});

test('row views retain independent dirty values and saveAll persists each one', function () {
    $products = Product::where('id', '<=', 3)->orderBy('id');
    $kept = [];
    foreach ($products as $product) {
        $product->title = 'Exact ' . $product->id;
        $kept[] = $product;
    }

    expect($kept[0]->id)->toBe(1)
        ->and($kept[1]->id)->toBe(2)
        ->and($kept[0]->title)->toBe('Exact 1');

    $products->saveAll();

    expect(Product::findMany([1, 2, 3])->orderBy('id')->pluck('title'))
        ->toBe(['Exact 1', 'Exact 2', 'Exact 3']);
});

test('save rejects an ambiguous dirty set while saveCurrent is row bound', function () {
    $products = Product::whereIn('id', [1, 2])->orderBy('id');
    $first = $products[0];
    $second = $products[1];
    $first->title = 'One';
    $second->title = 'Two';

    expect(fn () => $products->save())->toThrow(\Elveneek\Exception\AmbiguousWriteException::class);

    $first->saveCurrent();
    expect(Product::find(1)->title)->toBe('One')
        ->and(Product::find(2)->title)->toBe('Two'); // shared dirty state, not yet database confirmation

    Product::flushIdentityCache();
    expect(Product::find(2)->title)->toBe('Second product');
});

test('safe where forms and mutable grouped callback compile predictably', function () {
    $query = Product::where('category_id', 1)
        ->whereGroup(function ($query) {
            $query->whereLike('title', 'First%')->orWhereLike('title', 'Second%');
        })
        ->whereNotIn('id', []);

    expect($query->pluck('id'))->toBe([1, 2])
        ->and(substr_count($query->toSql(), '?'))->toBe(3)
        ->and($query->bindings())->toBe([1, 'First%', 'Second%']);

    expect(Product::whereIn('id', [])->count())->toBe(0)
        ->and(Product::whereNotIn('id', [])->count())->toBe(5)
        ->and(Product::where('menu_id', null)->count())->toBe(5);
});

test('nested AND and OR groups preserve parentheses bindings and results', function () {
    $query = Product::where('category_id', 1)
        ->whereGroup(function ($query) {
            $query->where('id', 1)
                ->orWhereGroup(function ($query) {
                    $query->where('brand_id', 2)
                        ->where('title', 'Second product');
                });
        })
        ->orderBy('id');

    expect($query->toSql())
        ->toContain('WHERE `category_id` = ? AND (`id` = ? OR (`brand_id` = ? AND `title` = ?))')
        ->and($query->bindings())->toBe([1, 1, 2, 'Second product'])
        ->and($query->pluck('id'))->toBe([1, 2]);
});

test('callable where and orWhere support recursively nested groups', function () {
    $query = Product::where(function ($query) {
        $query->where('category_id', 2)
            ->where('brand_id', 3);
    })->orWhere(function ($query) {
        $query->where('category_id', 1)
            ->where(function ($query) {
                $query->where('brand_id', 2)
                    ->orWhere('id', 5);
            });
    })->orderBy('id');

    expect($query->toSql())
        ->toContain('WHERE (`category_id` = ? AND `brand_id` = ?) OR (`category_id` = ? AND (`brand_id` = ? OR `id` = ?))')
        ->and($query->bindings())->toBe([2, 3, 1, 2, 5])
        ->and($query->pluck('id'))->toBe([2, 3, 5]);
});
test('identity map reuses complete rows and reloads missing partial columns', function () {
    Product::flushIdentityCache();
    \Elveneek\DB::flushQueryLog();
    expect(Product::find(1)->title)->toBe('First product');
    expect(Product::find(1)->title)->toBe('First product');
    $sqlEvents = array_values(array_filter(\Elveneek\DB::queryLog(), fn ($event) => $event['sql'] !== null));
    expect($sqlEvents)->toHaveCount(1);

    Product::flushIdentityCache();
    \Elveneek\DB::flushQueryLog();
    expect(Product::select('id', 'title')->findOne(1)->title)->toBe('First product');
    expect(Product::find(1)->brand_id)->toBe(1);
    $sqlEvents = array_values(array_filter(\Elveneek\DB::queryLog(), fn ($event) => $event['sql'] !== null));
    expect($sqlEvents)->toHaveCount(2);
});

test('join by relation keeps base identity and exposes aliased extras', function () {
    $product = Product::join('category')
        ->select('products.*')
        ->addSelect('category.title AS category_title')
        ->where('products.id', 1)
        ->firstOrFail();

    expect($product->id)->toBe(1)
        ->and($product->category_title)->toBe('First category');
});

test('collection context batches belongs-to loading and whereHas uses exists', function () {
    Product::flushIdentityCache();
    \Elveneek\DB::flushQueryLog();
    $titles = [];
    foreach (Product::orderBy('id') as $product) {
        $titles[] = $product->category?->title;
    }
    $sqlEvents = array_values(array_filter(\Elveneek\DB::queryLog(), fn ($event) => $event['sql'] !== null));

    expect($sqlEvents)->toHaveCount(2)
        ->and($titles[0])->toBe('First category')
        ->and(Product::whereHas('category', fn ($category) => $category->where('id', 2))->count())->toBe(2)
        ->and(Category::has('products')->count())->toBe(2);
});
test('batch draft insertion, aggregates and bulk update are available', function () {
    $news = Product::create()
        ->addRow(['title' => 'Draft A'])
        ->addRow(['title' => 'Draft B'])
        ->saveAll();

    expect($news->affectedRows())->toBeGreaterThanOrEqual(2)
        ->and(Product::whereLike('title', 'Draft %')->count())->toBe(2)
        ->and(Product::max('id'))->toBeGreaterThanOrEqual(7);

    $affected = Product::whereLike('title', 'Draft %')->updateAll(['type' => 'bulk']);
    expect($affected)->toBe(2)
        ->and(Product::where('type', 'bulk')->count())->toBe(2);
});

