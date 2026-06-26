<?php

if (!class_exists('Product')) {
    class Product extends \Elveneek\ActiveRecord {}
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

function sliceIds(array $slices): array
{
    return array_map(fn ($row) => array_map(fn ($p) => $p->id, iterator_to_array($row)), $slices);
}

test('slice cuts the result into rows of the requested size', function () {
    $slices = Product::orderBy('id')->slice(3);

    expect(count($slices))->toBe(2)
        ->and(sliceIds($slices))->toBe([[1, 2, 3], [4, 5]]);
});

test('slice default size is two', function () {
    expect(sliceIds(Product::orderBy('id')->slice()))->toBe([[1, 2], [3, 4], [5]]);
});

test('slice with size one yields one product per row', function () {
    $slices = Product::orderBy('id')->slice(1);

    expect(count($slices))->toBe(5)
        ->and(sliceIds($slices))->toBe([[1], [2], [3], [4], [5]]);
});

test('slice larger than the set yields a single row with everything', function () {
    expect(sliceIds(Product::orderBy('id')->slice(100)))->toBe([[1, 2, 3, 4, 5]]);
});

test('each slice row is a fully iterable and countable collection', function () {
    $slices = Product::orderBy('id')->slice(2);
    $first = $slices[0];

    expect(count($first))->toBe(2)
        ->and($first->count())->toBe(2)
        ->and($first[0]->id)->toBe(1)
        ->and($first[1]->id)->toBe(2)
        ->and($first)->toBeInstanceOf(\Traversable::class)
        ->and($first)->toBeInstanceOf(\Countable::class);
});

test('the template grid pattern works with nested foreach', function () {
    $rendered = [];
    foreach (Product::orderBy('id')->slice(3) as $row) {
        $titles = [];
        foreach ($row as $product) {
            $titles[] = $product->title;
        }
        $rendered[] = implode(' | ', $titles);
    }

    expect($rendered)->toBe([
        'First product | Second product | Third product',
        'Fourth product | Fifth Product',
    ]);
});

test('slice preserves the underlying query order', function () {
    $slices = Product::orderBy('id', 'desc')->slice(2);

    expect(sliceIds($slices))->toBe([[5, 4], [3, 2], [1]]);
});

test('slice on an empty result returns no rows', function () {
    expect(Product::where('id', 999)->slice(3))->toBe([]);
});

test('slice rejects a non positive size', function () {
    expect(fn () => Product::all()->slice(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Product::all()->slice(-2))->toThrow(InvalidArgumentException::class);
});

test('slice works as a static factory too', function () {
    $slices = Product::orderBy('id')->slice(2);

    expect(count($slices))->toBe(3);
});

test('a sliced row keeps the models writable and bound to their state', function () {
    $row = Product::orderBy('id')->slice(3)[0];
    $row->rewind();
    $product = $row->current();

    $product->title = 'Mutated in slice';

    expect($product->title)->toBe('Mutated in slice')
        ->and($product->isDirty('title'))->toBeTrue();
});

test('slice respects a select projection', function () {
    $row = Product::select('id', 'title')->orderBy('id')->slice(2)[0];

    expect($row[0]->title)->toBe('First product')
        ->and($row[0]->category_id)->toBeNull();
});
