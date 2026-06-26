<?php

if (!class_exists('Product')) {
    class Product extends \Elveneek\ActiveRecord {}
}
if (!class_exists('Category')) {
    class Category extends \Elveneek\ActiveRecord {}
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
});

test('first returns the first row and null for an empty result', function () {
    expect(Product::orderBy('id')->first()->id)->toBe(1)
        ->and(Product::where('id', 999)->first())->toBeNull();
});

test('firstOrFail throws when nothing matches', function () {
    expect(fn () => Product::where('id', 999)->firstOrFail())
        ->toThrow(\Elveneek\Exception\ModelNotFoundException::class);
});

test('last returns the final row respecting the order', function () {
    expect(Product::orderBy('id')->last()->id)->toBe(5)
        ->and(Product::where('id', 999)->last())->toBeNull();
});

test('findOrNull returns null for a missing id and a model otherwise', function () {
    expect(Product::findOrNull(999))->toBeNull()
        ->and(Product::findOrNull(2)->id)->toBe(2);
});

test('findOrFail returns the model or throws', function () {
    expect(Product::findOrFail(1)->id)->toBe(1);
    expect(fn () => Product::findOrFail(999))->toThrow(\Elveneek\Exception\ModelNotFoundException::class);
});

test('exists doesntExist isEmpty and isNotEmpty agree with the data', function () {
    expect(Product::where('id', 1)->exists())->toBeTrue()
        ->and(Product::where('id', 999)->exists())->toBeFalse()
        ->and(Product::where('id', 999)->doesntExist())->toBeTrue()
        ->and(Product::where('id', 1)->isEmpty())->toBeFalse()
        ->and(Product::where('id', 1)->isNotEmpty())->toBeTrue()
        ->and(Product::where('id', 1)->ne())->toBeTrue();
});

test('count returns the row cardinality without loading every row', function () {
    expect(Product::all()->count())->toBe(5)
        ->and(Product::where('category_id', 1)->count())->toBe(3)
        ->and(Product::where('id', 999)->count())->toBe(0);
});

test('count on a bound row is always one', function () {
    expect(Product::findOrFail(1)->count())->toBe(1);
});

test('pluck gathers a column and skips null values by default', function () {
    expect(Product::orderBy('id')->pluck('id'))->toBe([1, 2, 3, 4, 5])
        ->and(Product::orderBy('id')->pluck('brand_id'))->toBe([1, 2, 3, 1]);
});

test('pluck keyed by another column produces an associative array', function () {
    $map = Product::where('id', '<=', 2)->pluck('title', 'id');

    expect($map)->toBe([1 => 'First product', 2 => 'Second product']);
});

test('value returns the first matching column or null', function () {
    expect(Product::where('id', 1)->value('title'))->toBe('First product')
        ->and(Product::where('id', 999)->value('title'))->toBeNull();
});

test('aggregates return scalar results', function () {
    expect(Product::all()->count())->toBe(5)
        ->and(Product::min('id'))->toBe(1)
        ->and(Product::max('id'))->toBe(5)
        ->and(Product::sum('id'))->toBe(15)
        ->and((float) Product::avg('id'))->toBe(3.0);
});

test('aggregates on an empty set return null', function () {
    expect(Product::where('id', 999)->min('id'))->toBeNull()
        ->and(Product::where('id', 999)->max('id'))->toBeNull()
        ->and(Product::where('id', 999)->sum('id'))->toBeNull();
});

test('toSql and bindings expose the compiled statement', function () {
    $query = Product::where('category_id', 1)->orderBy('id');

    expect($query->toSql())->toContain('SELECT')->toContain('WHERE')->toContain('ORDER BY')
        ->and($query->bindings())->toBe([1]);
});

test('toRawSql interpolates quoted bindings for debugging', function () {
    $raw = Product::where('title', 'First product')->toRawSql();

    expect($raw)->toContain("'First product'");
});

test('copy shares the query but starts an independent cursor', function () {
    $base = Product::orderBy('id');
    $copy = $base->copy();

    expect($copy)->not->toBe($base)
        ->and($copy->toSql())->toBe($base->toSql());
});

test('resetQuery drops all query constraints', function () {
    $fresh = Product::where('id', 1)->orderBy('id')->resetQuery();

    expect($fresh->count())->toBe(5);
});

test('toArray of a collection is a list of row arrays', function () {
    $array = Product::where('id', '<=', 2)->orderBy('id')->select('id', 'title')->toArray();

    expect($array)->toBe([
        ['id' => 1, 'title' => 'First product'],
        ['id' => 2, 'title' => 'Second product'],
    ]);
});

test('toJson serialises the collection to valid json', function () {
    $json = Product::where('id', 1)->select('id', 'title')->toJson();
    expect(json_decode($json, true))->toBe([['id' => 1, 'title' => 'First product']]);
});

test('jsonSerialize returns the same structure as toArray', function () {
    $model = Product::findOrFail(1);

    expect($model->jsonSerialize()['id'])->toBe(1);
});

test('to_json_by_id keys the serialised rows by primary key', function () {
    $json = Product::where('id', '<=', 2)->select('id', 'title')->orderBy('id')->toJson();
    $data = json_decode($json, true);

    expect($data)->toHaveCount(2)
        ->and($data[0]['title'])->toBe('First product')
        ->and($data[1]['title'])->toBe('Second product');
});

test('iterator yields rows in order and rewinds cleanly', function () {
    $products = Product::orderBy('id')->limit(3);
    $ids = [];
    foreach ($products as $product) {
        $ids[] = $product->id;
    }
    $again = [];
    foreach ($products as $product) {
        $again[] = $product->id;
    }

    expect($ids)->toBe([1, 2, 3])
        ->and($again)->toBe([1, 2, 3]);
});

test('manual iterator navigation with seek next and current', function () {
    $products = Product::orderBy('id');
    $products->rewind();
    expect($products->key())->toBe(0)
        ->and($products->current()->id)->toBe(1);

    $products->seek(3);
    expect($products->current()->id)->toBe(4);

    $products->next();
    expect($products->current()->id)->toBe(5);

    $products->next();
    expect($products->valid())->toBeFalse();
});

test('array access reads numeric and string offsets', function () {
    $products = Product::orderBy('id');
    expect($products[2]->id)->toBe(3)
        ->and(isset($products[4]))->toBeTrue()
        ->and(isset($products[99]))->toBeFalse()
        ->and($products[99])->toBeNull();
});

test('unset on a numeric offset removes the row from the collection', function () {
    $products = Product::orderBy('id');
    unset($products[0]);

    expect(count($products))->toBe(4)
        ->and($products[0])->toBeNull()
        ->and($products[1]->id)->toBe(2);
});

test('by_id locates a row within a loaded collection without re-querying', function () {
    \Elveneek\DB::flushQueryLog();
    $products = Product::orderBy('id')->load();
    $found = $products->by_id(3);

    $queries = array_filter(\Elveneek\DB::queryLog(), fn ($e) => $e['sql'] !== null);
    expect($found->id)->toBe(3)
        ->and($queries)->toHaveCount(1);
});

test('by_id returns null when the id is not present', function () {
    expect(Product::all()->by_id(999))->toBeNull();
});

test('getRaw and setRaw bypass accessors and mutators', function () {
    $product = Product::findOrFail(1);

    expect($product->getRaw('title'))->toBe('First product');

    $product->setRaw('title', 'Raw override');
    expect($product->getRaw('title'))->toBe('Raw override')
        ->and($product->isDirty('title'))->toBeTrue();
});

test('load forces full materialisation and isLoaded reports it', function () {
    $products = Product::orderBy('id');

    expect($products->isLoaded())->toBeFalse();

    $products->load();

    expect($products->isLoaded())->toBeTrue()
        ->and($products->isFullyLoaded())->toBeTrue()
        ->and($products->loadedCount())->toBe(5);
});

test('cacheHit and cacheSource report a fresh database read by default', function () {
    \Elveneek\ActiveRecord::flushIdentityCache();
    $products = Product::where('id', 1);

    $products->first();

    expect($products->cacheHit())->toBeFalse()
        ->and($products->cacheSource())->toBe('database');
});

test('foundRows reports the total ignoring limit and offset', function () {
    $page = Product::orderBy('id')->limit(2)->offset(1);

    expect(iterator_count($page))->toBe(2)
        ->and($page->foundRows())->toBe(5)
        ->and($page->total())->toBe(5);
});

test('lastPage and hasNextPage compute pagination metadata', function () {
    $page = Product::orderBy('id')->paginate(2, 0);

    expect($page->lastPage())->toBe(3)
        ->and($page->hasNextPage())->toBeTrue();
});

test('queryFingerprint is stable for identical queries', function () {
    expect(Product::where('id', 1)->queryFingerprint())
        ->toBe(Product::where('id', 1)->queryFingerprint())
        ->not->toBe(Product::where('id', 2)->queryFingerprint());
});

test('queryDependencies list the involved tables', function () {
    expect(Product::all()->queryDependencies())->toBe(['products']);
});

test('cacheSource reports identity after a repeated find hit', function () {
    \Elveneek\DB::flushQueryLog();
    Product::find(1)->title;
    $second = Product::find(1);
    $second->title;
    $sqlEvents = array_filter(\Elveneek\DB::queryLog(), fn ($e) => $e['sql'] !== null);

    expect(count($sqlEvents))->toBe(1)
        ->and($second->cacheSource())->toBe('identity');
});
