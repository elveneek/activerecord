<?php

function as_uppercase($value, $field, $object): string
{
    return strtoupper((string) $value);
}

function as_suffix($value, $field, $object): string
{
    return $value . '!';
}

class As_reversed
{
    public static function call($value, $field, $object): string
    {
        return strrev((string) $value);
    }
}

if (!class_exists('Product')) {
    class Product extends \Elveneek\ActiveRecord {}
}
if (!class_exists('Category')) {
    class Category extends \Elveneek\ActiveRecord {}
}

class AccessorProduct extends \Elveneek\ActiveRecord
{
    protected static string $table = 'products';

    public function getUpperTitle(): string
    {
        return strtoupper((string) $this->getRaw('title'));
    }

    public function getLabel(): string
    {
        return 'item-' . $this->getRaw('id');
    }

    public function setTitle($value): ?string
    {
        $this->setRaw('title', trim((string) $value));
        return null;
    }

    public function setCounter($value): ?int
    {
        $this->setRaw('menu_id', (int) $value);
        return null;
    }
}

class StrictProduct extends \Elveneek\ActiveRecord
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
    AccessorProduct::strictMode(false);
});

test('a getX accessor is invoked when reading the attribute', function () {
    $product = AccessorProduct::findOrFail(1);

    expect($product->upper_title)->toBe('FIRST PRODUCT')
        ->and($product->label)->toBe('item-1');
});

test('a setX mutator transforms the value before it is stored', function () {
    $product = AccessorProduct::findOrFail(1);

    $product->title = '  Spaced  ';

    expect($product->getRaw('title'))->toBe('Spaced')
        ->and($product->isDirty('title'))->toBeTrue();
});

test('a mutator returning null signals it handled the assignment', function () {
    $product = AccessorProduct::findOrFail(1);
    $product->counter = '42';

    expect($product->getRaw('menu_id'))->toBe(42);
});

test('title_as_ formatter resolves a global function', function () {
    expect(AccessorProduct::findOrFail(1)->title_as_uppercase)->toBe('FIRST PRODUCT');
});

test('title_as_ formatter resolves a service class', function () {
    expect(AccessorProduct::findOrFail(1)->title_as_reversed)->toBe('tcudorp tsriF');
});

test('a missing formatter throws with a descriptive message', function () {
    expect(fn () => AccessorProduct::findOrFail(1)->title_as_missing)
        ->toThrow(\BadMethodCallException::class);
});

test('an invalid formatter name throws without crashing the process', function () {
    expect(fn () => AccessorProduct::findOrFail(1)->{'_as_'})->not->toThrow(\Error::class)
        ->and(fn () => AccessorProduct::findOrFail(1)->{'title_as_'})
        ->toThrow(\BadMethodCallException::class);
});

test('reading an unselected known column returns null in lenient mode', function () {
    $product = AccessorProduct::select('id')->where('id', 1)->first();

    expect($product->title)->toBeNull();
});

test('strict mode throws when reading an unselected known column', function () {
    AccessorProduct::strictMode(true);
    try {
        $product = AccessorProduct::select('id')->where('id', 1)->first();
        expect(fn () => $product->title)
            ->toThrow(\Elveneek\Exception\MissingAttributeException::class);
    } finally {
        AccessorProduct::strictMode(false);
    }
});

test('strict mode throws when reading a truly unknown attribute', function () {
    StrictProduct::strictMode(true);
    try {
        $product = StrictProduct::findOrFail(1);
        expect(fn () => $product->totally_unknown)
            ->toThrow(\Elveneek\Exception\UnknownAttributeOrRelationException::class);
    } finally {
        StrictProduct::strictMode(false);
    }
});

test('lenient mode returns null for a truly unknown attribute', function () {
    expect(AccessorProduct::findOrFail(1)->totally_unknown)->toBeNull();
});

test('isset reports known columns and relations but not unknown names', function () {
    $product = AccessorProduct::findOrFail(1);

    expect(isset($product->title))->toBeTrue()
        ->and(isset($product->brand_id))->toBeTrue()
        ->and(isset($product->totally_unknown))->toBeFalse();
});

test('array offset access mirrors attribute reads', function () {
    $product = AccessorProduct::findOrFail(1);

    expect($product['id'])->toBe(1)
        ->and($product['title'])->toBe('First product');
});

test('assigning a record to a foreign key field stores its primary key', function () {
    $product = AccessorProduct::findOrFail(1);
    $category = AccessorProduct::findOrFail(1);

    $product->category = $category;

    expect($product->category_id)->toBe($category->id);
});

test('nulling a foreign key field clears the relation cache', function () {
    $product = AccessorProduct::findOrFail(1);

    $product->category_id = null;

    expect($product->category_id)->toBeNull();
});

test('SQL_NULL constant assigns a real null on save', function () {
    $product = AccessorProduct::create(['title' => 'Nuller']);
    $product->text = SQL_NULL;
    $product->save();

    expect(AccessorProduct::findOrFail($product->id)->text)->toBeNull();
});

test('empty string on an _at field is converted to null', function () {
    $product = AccessorProduct::create(['title' => 'Dated']);
    $product->updated_at = '';
    $product->save();

    expect($product->getRaw('updated_at'))->toBeNull();
});

test('fromTable builds a model from a table name when the class exists', function () {
    $model = \Elveneek\ActiveRecord::fromTable('products');

    expect($model)->toBeInstanceOf(\Elveneek\ActiveRecord::class)
        ->and($model->where('id', 1)->first()->title)->toBe('First product');
});

test('fromTable returns a generic table model when the matching model class is missing', function () {
    $model = \Elveneek\ActiveRecord::fromTable('no_such_table');

    expect($model)->toBeInstanceOf(\Elveneek\TableRecord::class)
        ->and($model->table)->toBe('no_such_table');
});

test('one_to_plural and plural_to_one delegate to the inflector', function () {
    expect(\Elveneek\ActiveRecord::one_to_plural('product'))->toBe('products')
        ->and(\Elveneek\ActiveRecord::plural_to_one('categories'))->toBe('category');
});

test('cacheSource reports database on a fresh cold query', function () {
    \Elveneek\ActiveRecord::flushIdentityCache();
    $products = AccessorProduct::where('id', 1);
    $products->first();

    expect($products->cacheSource())->toBe('database')
        ->and($products->cacheHit())->toBeFalse();
});

test('snapshot and restore round trip the identity map', function () {
    AccessorProduct::findOrFail(1);
    $snapshot = AccessorProduct::captureIdentitySnapshot();

    AccessorProduct::flushIdentityCache();
    expect(AccessorProduct::captureIdentitySnapshot()['states'])->toBe([]);

    AccessorProduct::restoreIdentitySnapshot($snapshot);
    expect(AccessorProduct::captureIdentitySnapshot()['states'])->not->toBe([]);
});

test('runtime snapshot captures query cache and table generations', function () {
    $keys = array_keys(\Elveneek\ActiveRecord::captureRuntimeSnapshot());
    expect($keys)->toBe(['identity', 'queryResultCache', 'tableGenerations']);
});
