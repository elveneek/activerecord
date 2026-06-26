<?php

/**
 * Этот файл намеренно фиксирует итераторный (IteratorAggregate), массивовый
 * (ArrayAccess) и счётный (Countable) контракты ActiveRecord. foreach, [],
 * isset, empty, count() и ручная навигация курсором должны работать
 * предсказуемо и согласованно.
 */

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

test('foreach iterates rows with sequential numeric keys', function () {
    $products = Product::orderBy('id');

    $keys = [];
    $ids = [];
    foreach ($products as $key => $product) {
        $keys[] = $key;
        $ids[] = $product->id;
    }

    expect($keys)->toBe([0, 1, 2, 3, 4])
        ->and($ids)->toBe([1, 2, 3, 4, 5]);
});

test('value only foreach works without a key binding', function () {
    $titles = [];
    foreach (Product::orderBy('id')->limit(2) as $product) {
        $titles[] = $product->title;
    }

    expect($titles)->toBe(['First product', 'Second product']);
});

test('foreach can be repeated and each run starts from the beginning', function () {
    $products = Product::orderBy('id')->limit(3);

    $first = [];
    foreach ($products as $p) {
        $first[] = $p->id;
    }
    $second = [];
    foreach ($products as $p) {
        $second[] = $p->id;
    }

    expect($first)->toBe([1, 2, 3])
        ->and($second)->toBe([1, 2, 3]);
});

test('nested foreach keeps an independent inner cursor', function () {
    $products = Product::orderBy('id')->limit(2);

    $pairs = [];
    foreach ($products as $outer) {
        foreach ($products as $inner) {
            $pairs[] = $outer->id . '-' . $inner->id;
        }
    }

    expect($pairs)->toBe(['1-1', '1-2', '2-1', '2-2']);
});

test('foreach over an empty result yields nothing', function () {
    $count = 0;
    foreach (Product::where('id', 999) as $p) {
        $count++;
    }

    expect($count)->toBe(0);
});

test('iterator count helper reports the number of yielded rows', function () {
    expect(iterator_count(Product::orderBy('id')))->toBe(5)
        ->and(iterator_count(Product::where('id', 999)))->toBe(0);
});

test('manual iterator protocol rewind valid current key next', function () {
    $products = Product::orderBy('id')->limit(2);

    $products->rewind();
    expect($products->valid())->toBeTrue()
        ->and($products->key())->toBe(0)
        ->and($products->current()->id)->toBe(1);

    $products->next();
    expect($products->key())->toBe(1)
        ->and($products->current()->id)->toBe(2);

    $products->next();
    expect($products->valid())->toBeFalse()
        ->and($products->current())->toBeNull();

    $products->rewind();
    expect($products->valid())->toBeTrue()
        ->and($products->current()->id)->toBe(1);
});

test('seek positions the cursor at the requested index', function () {
    $products = Product::orderBy('id');

    expect($products->seek(3)->current()->id)->toBe(4)
        ->and($products->key())->toBe(3);
});

test('the model implements traversable array access and countable', function () {
    $products = Product::all();

    expect($products)->toBeInstanceOf(\Traversable::class)
        ->and($products)->toBeInstanceOf(\IteratorAggregate::class)
        ->and($products)->toBeInstanceOf(\ArrayAccess::class)
        ->and($products)->toBeInstanceOf(\Countable::class);
});

test('numeric array access reads and bounds checks rows', function () {
    $products = Product::orderBy('id');

    expect($products[0]->id)->toBe(1)
        ->and($products[4]->id)->toBe(5)
        ->and(isset($products[0]))->toBeTrue()
        ->and(isset($products[4]))->toBeTrue()
        ->and(isset($products[5]))->toBeFalse()
        ->and(isset($products[-1]))->toBeFalse()
        ->and($products[99])->toBeNull();
});

test('string array access reads writes and unsets attributes on the current row', function () {
    $product = Product::findOrFail(1);

    expect($product['title'])->toBe('First product')
        ->and(isset($product['title']))->toBeTrue();

    $product['title'] = 'Via bracket';
    expect($product['title'])->toBe('Via bracket')
        ->and($product->isDirty('title'))->toBeTrue();

    unset($product['title']);
    expect($product->getRaw('title'))->toBeNull();
});

test('empty on an offset is true when the value is null', function () {
    $product = Product::findOrFail(1);

    expect(empty($product['title']))->toBeFalse()
        ->and(empty($product['menu_id']))->toBeTrue()
        ->and(empty($product['does_not_exist']))->toBeTrue();
});

test('empty on the collection object is always false regardless of count', function () {
    // PHP objects are always truthy, so empty($collection) is false even when
    // the result set is empty. Countable only affects count(), not empty().
    // Use isEmpty() to check whether the set has rows.
    expect(empty(Product::all()))->toBeFalse()
        ->and(empty(Product::where('id', 999)))->toBeFalse();
});

test('isEmpty is the correct emptiness check for the set', function () {
    expect(Product::all()->isEmpty())->toBeFalse()
        ->and(Product::where('id', 999)->isEmpty())->toBeTrue()
        ->and(Product::all()->isNotEmpty())->toBeTrue();
});

test('the php count function works on the collection via countable', function () {
    expect(count(Product::all()))->toBe(5)
        ->and(count(Product::where('id', 999)))->toBe(0);
});

test('foreach still works after a mutation through array access', function () {
    $products = Product::orderBy('id')->limit(2);

    foreach ($products as $product) {
        $product['type'] = 'iterated';
    }

    expect($products[0]->type)->toBe('iterated')
        ->and($products[1]->type)->toBe('iterated');
});

test('foreach respects an explicit order clause', function () {
    $ids = [];
    foreach (Product::orderBy('id', 'desc')->limit(3) as $p) {
        $ids[] = $p->id;
    }

    expect($ids)->toBe([5, 4, 3]);
});

test('foreach over a manually loaded collection yields the same rows', function () {
    $products = Product::orderBy('id')->load();

    $ids = [];
    foreach ($products as $p) {
        $ids[] = $p->id;
    }

    expect($ids)->toBe([1, 2, 3, 4, 5]);
});

test('array access returns row bound models that share identity', function () {
    $products = Product::orderBy('id');

    $a = $products[0];
    $b = $products[0];

    expect($a->id)->toBe(1)
        ->and($b->id)->toBe(1);
});
