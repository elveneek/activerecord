<?php

/**
 * Покрывает "linked" обход через префикс "_": собирает ID из текущего набора
 * и делает WHERE id IN (...) на следующем шаге. Работает в обе стороны:
 *   - направо (hasMany):  Region->_cities, Employee->_photos, Photo->_tags
 *   - налево  (belongsTo, собранный по группе, с дедупликацией):
 *                Employees->_clients, Clients->_users, Products->_brands
 *
 * allLinked() тут намеренно не тестируется — это compatibility-метод.
 */

if (!class_exists('Region')) {
    class Region extends \Elveneek\ActiveRecord {}
}
if (!class_exists('City')) {
    class City extends \Elveneek\ActiveRecord {}
}
if (!class_exists('Client')) {
    class Client extends \Elveneek\ActiveRecord {}
}
if (!class_exists('User')) {
    class User extends \Elveneek\ActiveRecord {}
}
if (!class_exists('Employee')) {
    class Employee extends \Elveneek\ActiveRecord {}
}
if (!class_exists('Photo')) {
    class Photo extends \Elveneek\ActiveRecord {}
}
if (!class_exists('Tag')) {
    class Tag extends \Elveneek\ActiveRecord {}
}
if (!class_exists('Brand')) {
    class Brand extends \Elveneek\ActiveRecord {}
}
if (!class_exists('Catalog')) {
    class Catalog extends \Elveneek\ActiveRecord {}
}
if (!class_exists('Product')) {
    class Product extends \Elveneek\ActiveRecord {}
}

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }
    $pdo = \Elveneek\ActiveRecord::connect();
    \Elveneek\DB::setConnection($pdo);

    $pdo->exec(file_get_contents(__DIR__ . '/data/linked_traversal.sql'));

    \Elveneek\ActiveRecord::flushIdentityCache();
    \Elveneek\ActiveRecord::flushSchemaCache();
});

// --- направо: hasMany ------------------------------------------------------------

test('hasMany one hop right: region to its cities', function () {
    expect(Region::find(4)->_cities->orderBy('id')->pluck('id'))->toBe([4])
        ->and(Region::find(4)->_cities->count())->toBe(1);
});

test('hasMany multi hop right: region cities employees', function () {
    expect(Region::find(4)->_cities->_employees->orderBy('id')->pluck('id'))->toBe([3, 5]);
});

test('hasMany deep chain: region cities employees photos', function () {
    expect(Region::find(4)->_cities->_employees->_photos->orderBy('id')->pluck('id'))->toBe([3]);
});

test('hasMany from a whole collection: all brands into their products', function () {
    expect(Brand::all()->_products->count())->toBe(3)
        ->and(Brand::all()->_products->orderBy('id')->pluck('id'))->toBe([1, 2, 3]);
});

// --- налево: belongsTo, собранный по группе --------------------------------------

test('belongsTo collected left: employees of a region into their clients', function () {
    // emp3 -> client2, emp5 -> client NULL (skipped)
    expect(Region::find(4)->_cities->_employees->_clients->orderBy('id')->pluck('id'))->toBe([2]);
});

test('belongsTo collected left: clients into their managing users', function () {
    expect(Region::find(4)->_cities->_employees->_clients->_users->orderBy('id')->pluck('id'))->toBe([2])
        ->and(Region::find(4)->_cities->_employees->_clients->_users->pluck('name'))->toBe(['Bob']);
});

test('belongsTo collected left: products into their brands and catalogs', function () {
    expect(Product::all()->_brands->orderBy('id')->pluck('id'))->toBe([1, 2])
        ->and(Product::all()->_catalogs->orderBy('id')->pluck('id'))->toBe([1, 2]);
});

// --- смешанные цепочки и твои примеры --------------------------------------------

test('example: tags of photos of employees of clients in region 3', function () {
    $tagIds = Region::find(3)->_cities->_clients->_employees->_photos->_tags->orderBy('id')->pluck('id');

    expect($tagIds)->toBe([1, 2, 3, 4]);
});

test('example: Apple brand into its products and their catalogs', function () {
    $catalogTitles = Brand::where('title = ?', 'Apple')->_products->_catalogs->orderBy('id')->pluck('title');

    expect($catalogTitles)->toBe(['Phones', 'Laptops']);
});

test('mixed chain hasMany then belongsTo then hasMany', function () {
    // region -> cities (hasMany) -> clients (hasMany, clients.city_id)
    // -> employees (hasMany) -> photos (hasMany) -> tags (hasMany)
    $ids = Region::find(3)->_cities->_clients->_employees->_photos->_tags->orderBy('id')->pluck('id');

    expect($ids)->toBe([1, 2, 3, 4]);
});

// --- дедупликация и null FK ------------------------------------------------------

test('collected belongsTo deduplicates shared owners', function () {
    // emp1 and emp2 both belong to client 1
    expect(Employee::where('client_id', 1)->_clients->count())->toBe(1)
        ->and(Employee::where('client_id', 1)->_clients->pluck('id'))->toBe([1]);
});

test('null foreign keys mid chain are skipped without errors', function () {
    // emp5 has client_id NULL, emp3 has client 2
    $clientIds = Employee::where('city_id', 4)->_clients->orderBy('id')->pluck('id');

    expect($clientIds)->toBe([2]);
});

test('an empty hop yields an empty set and the chain keeps working', function () {
    expect(Employee::find(2)->_photos->isEmpty())->toBeTrue()
        ->and(Employee::find(2)->_photos->_tags->isEmpty())->toBeTrue();
});

test('an empty starting set produces an empty downstream set', function () {
    expect(Brand::where('title', 'Nonexistent')->_products->isEmpty())->toBeTrue()
        ->and(Brand::where('title', 'Nonexistent')->_products->_catalogs->isEmpty())->toBeTrue();
});

// --- _ против прямой belongsTo ---------------------------------------------------

test('direct singular belongsTo returns one model on a single record', function () {
    expect(Employee::find(1)->client->id)->toBe(1)
        ->and(Employee::find(1)->client)->toBeInstanceOf(Client::class);
});

test('underscore traversal on a collection collects across the whole set', function () {
    // two employees of client 1 collapse into one collected client
    expect(Employee::where('client_id', 1)->_clients->count())->toBe(1);
});

test('underscore on a single belongsTo also resolves the related row', function () {
    expect(Employee::find(3)->_clients->first()->id)->toBe(2);
});

// --- обратные обходы -------------------------------------------------------------

test('reverse traversal: photos up to their employees and clients', function () {
    expect(Photo::find(1)->_employees->first()->id)->toBe(1)
        ->and(Photo::all()->_employees->orderBy('id')->pluck('id'))->toBe([1, 3, 4]);
});

test('reverse traversal: products up to brands and back down to products', function () {
    $siblingProductIds = Product::find(1)->_brands->_products->orderBy('id')->pluck('id');

    // brand 1 (Apple) owns product 1 and 2
    expect($siblingProductIds)->toBe([1, 2]);
});

// --- гранничные случаи -----------------------------------------------------------

test('related returns null for an unresolvable relation name', function () {
    expect(Region::find(1)->related('_unicorns'))->toBeNull();
});

test('a long six hop chain resolves end to end', function () {
    // region -> cities -> clients -> employees -> photos -> tags
    $count = Region::find(3)->_cities->_clients->_employees->_photos->_tags->count();

    expect($count)->toBe(4);
});

test('traversal result supports count and foreach like any collection', function () {
    $photos = Region::find(4)->_cities->_employees->_photos;
    $urls = [];
    foreach ($photos as $photo) {
        $urls[] = $photo->url;
    }

    expect(count($photos))->toBe(1)
        ->and($urls)->toBe(['p3']);
});

test('traversal works from find all where and a single find equally', function () {
    expect(Employee::all()->_photos->count())->toBe(4)
        ->and(Employee::where('client_id', 1)->_photos->count())->toBe(2)
        ->and(Employee::find(1)->_photos->count())->toBe(2);
});
