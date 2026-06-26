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
    \Elveneek\DB::flushQueryLog();
});

test('nested foreach iterations are independent', function () {
    $products = Product::orderBy('id')->limit(3);

    $first = [];
    foreach ($products as $p) {
        $first[] = $p->id;
        $inner = [];
        foreach ($products as $q) {
            $inner[] = $q->id;
        }
    }

    expect($first)->toBe([1, 2, 3]);
});

test('a collection can be counted before and after iteration', function () {
    $products = Product::all();

    expect(count($products))->toBe(5);
    foreach ($products as $p) {
    }
    expect(count($products))->toBe(5);
});

test('offsetSet on a string offset assigns an attribute on the cursor row', function () {
    $product = Product::findOrFail(1);
    $product['title'] = 'Via offset';

    expect($product->title)->toBe('Via offset')
        ->and($product->isDirty('title'))->toBeTrue();
});

test('offsetUnset on a string offset clears the in memory attribute', function () {
    $product = Product::findOrFail(1);
    unset($product['title']);

    expect($product->getRaw('title'))->toBeNull();
});

test('string offset access reads attributes and extras', function () {
    $product = Product::select('id', 'title')->findOrFail(1);

    expect($product['id'])->toBe(1)
        ->and($product['title'])->toBe('First product')
        ->and($product['missing'])->toBeNull();
});

test('isset on an attribute distinguishes null from missing', function () {
    $product = Product::findOrFail(1);

    expect(isset($product['title']))->toBeTrue()
        ->and(isset($product['menu_id']))->toBeTrue()
        ->and(isset($product['does_not_exist']))->toBeFalse();
});

test('empty returns true only for absent or null values', function () {
    $product = Product::findOrFail(1);

    expect(empty($product['title']))->toBeFalse()
        ->and(empty($product['menu_id']))->toBeTrue()
        ->and(empty($product['nope']))->toBeTrue();
});

test('the collection is countable traversable and array accessible', function () {
    $products = Product::all();

    expect($products)->toBeInstanceOf(\Countable::class)
        ->and($products)->toBeInstanceOf(\Traversable::class)
        ->and($products)->toBeInstanceOf(\ArrayAccess::class)
        ->and($products)->toBeInstanceOf(\JsonSerializable::class);
});

test('json_encode of a model yields the toArray structure', function () {
    $product = Product::select('id', 'title')->where('id', 1)->first();

    expect(json_decode(json_encode($product), true))->toBe(['id' => 1, 'title' => 'First product']);
});

test('to_json_by_id keys the serialised rows by primary key', function () {
    $json = Product::where('id', '<=', 2)->select('id', 'title')->orderBy('id')->to_json_by_id();
    $data = json_decode($json, true);

    expect($data)->toHaveKey(1)->toHaveKey(2)
        ->and($data['1']['title'])->toBe('First product')
        ->and($data['2']['title'])->toBe('Second product');
});

test('to_json_by_id supports a pretty print flag', function () {
    $compact = Product::where('id', 1)->select('id', 'title')->to_json_by_id();
    $pretty = Product::where('id', 1)->select('id', 'title')->to_json_by_id(JSON_PRETTY_PRINT);

    expect($compact)->not->toContain("\n")
        ->and($pretty)->toContain("\n")
        ->and(json_decode($pretty, true)['1']['title'])->toBe('First product');
});

test('toJson accepts flags and always throws on error', function () {
    $json = Product::where('id', 1)->select('id', 'title')->toJson(JSON_PRETTY_PRINT);

    expect($json)->toContain("\n")
        ->and(json_decode($json, true)[0]['title'])->toBe('First product');
});

test('selecting a subset exposes only those columns and nulls the rest', function () {
    $product = Product::select('id')->where('id', 1)->first();

    expect($product->id)->toBe(1)
        ->and($product->title)->toBeNull();
});

test('addSelect appends additional columns to a partial selection', function () {
    $product = Product::select('id')->addSelect('title')->where('id', 1)->first();

    expect($product->title)->toBe('First product');
});

test('a select alias becomes accessible as an extra attribute', function () {
    $row = Product::selectRaw('UPPER(title) AS upper_title')->where('id', 1)->firstOrFail();

    expect($row->upper_title)->toBe('FIRST PRODUCT');
});

test('iteration over an empty result yields nothing', function () {
    $count = 0;
    foreach (Product::where('id', 999) as $p) {
        $count++;
    }

    expect($count)->toBe(0);
});

test('accessing a property triggers exactly one query for lazy rows', function () {
    \Elveneek\DB::flushQueryLog();

    $product = Product::where('id', 1)->first();
    $product->title;

    $sqlEvents = array_filter(\Elveneek\DB::queryLog(), fn ($e) => $e['sql'] !== null);
    expect(count($sqlEvents))->toBe(1);
});

test('limit controls how many rows are materialised', function () {
    expect(iterator_count(Product::orderBy('id')->limit(2)))->toBe(2)
        ->and(iterator_count(Product::orderBy('id')->limit(0)))->toBe(0);
});

test('offset skips the leading rows', function () {
    $products = Product::orderBy('id')->limit(2)->offset(2);

    expect([$products[0]->id, $products[1]->id])->toBe([3, 4]);
});

test('orderBy asc and desc order rows accordingly', function () {
    expect(Product::orderBy('id', 'asc')->first()->id)->toBe(1)
        ->and(Product::orderBy('id', 'desc')->first()->id)->toBe(5);
});

test('multiple orderBy clauses apply in sequence', function () {
    $rows = Product::orderBy('category_id', 'asc')->orderBy('id', 'desc')->select('id')->pluck('id');

    expect($rows)->toBe([5, 2, 1, 4, 3]);
});

test('groupBy with having filters aggregated groups', function () {
    $groups = Product::select('category_id')
        ->selectRaw('COUNT(*) AS cnt')
        ->groupBy('category_id')
        ->havingRaw('cnt > ?', 2)
        ->orderBy('category_id')
        ->first();

    expect($groups->category_id)->toBe(1)
        ->and((int) $groups->cnt)->toBe(3);
});

test('distinct collapses duplicate value combinations', function () {
    expect(Product::select('category_id')->distinct()->orderBy('category_id')->pluck('category_id'))->toBe([1, 2]);
});

test('a raw where binds placeholders in order and respects null checks', function () {
    $rows = Product::whereRaw('category_id = ? AND brand_id IS NOT NULL', 1)->orderBy('id');

    expect($rows->pluck('id'))->toBe([1, 2]);
});

test('search builds an or-like across fields', function () {
    $results = Product::all()->search(['title', 'text'], 'Second');

    expect($results->pluck('id'))->toBe([2]);
});

test('when applies a callback conditionally and returns the query', function () {
    $applied = Product::all()->when(true, fn ($q) => $q->where('id', 1));
    $skipped = Product::all()->when(false, fn ($q) => $q->where('id', 1));

    expect($applied->first()->id)->toBe(1)
        ->and($skipped->count())->toBe(5);
});

test('unless applies a callback when the value is falsy', function () {
    $applied = Product::all()->unless(false, fn ($q) => $q->where('id', 2));

    expect($applied->first()->id)->toBe(2);
});

test('tap runs a side effect and returns the original query', function () {
    $tapped = null;
    $query = Product::all()->tap(function ($q) use (&$tapped) {
        $tapped = $q;
    });

    expect($query)->toBe($tapped);
});

test('orStub returns a stub when the result is empty', function () {
    $result = Product::where('id', 999)->orStub();

    expect($result->isEmpty())->toBeTrue()
        ->and($result->count())->toBe(0);
});

test('orStub returns the real result when it is non empty', function () {
    $result = Product::where('id', 1)->orStub();

    expect($result->first()->id)->toBe(1);
});

test('get and only both return attribute values', function () {
    $product = Product::findOrFail(1);

    expect($product->get('title'))->toBe('First product')
        ->and($product->only('title'))->toBe('First product');
});

test('table magic property exposes the table name', function () {
    expect(Product::findOrFail(1)->table)->toBe('products');
});

test('count and isEmpty magic properties work', function () {
    expect(Product::all()->count)->toBe(5)
        ->and(Product::where('id', 999)->isEmpty)->toBeTrue()
        ->and(Product::where('id', 1)->isNotEmpty)->toBeTrue();
});
