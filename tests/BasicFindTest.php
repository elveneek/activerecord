<?php



beforeAll(function () {
    echo 'beforeAll';
    Dotenv\Dotenv::createImmutable(__DIR__)->load();
    \Elveneek\ActiveRecord::$db = \Elveneek\ActiveRecord::connect();
    \Elveneek\ActiveRecord::$db->exec(file_get_contents(__DIR__ . '/data/mysql.sql'));

    class Product extends \Elveneek\ActiveRecord
    {
    }
});


test('basic static calls and fabrics', function () {
    expect(Product::find(2)->id)->toBe(2);
    expect(Product::where('id <> 3')->where('id<5 and id > 2')->id)->toBe(4);
    expect(Product::all()->where('id<3 and id > 1')->id)->toBe(2);
    expect(Product::where('id<3 and id > 1')->id)->toBe(2);
    expect(Product::where('id<3')->where('id > 1')->id)->toBe(2);
    expect(Product::all()->where('id<3 and id > 1')->id)->toBe(2);
    expect(Product::find_by('id', 2)->id)->toBe(2);
    expect(Product::all()->find_by('id', 2)->id)->toBe(2);
    expect(Product::all()->count)->toBe(5);

    expect(Product::w("id", 1)->id)->toBe(1);
    expect(Product::all()->w("id", 1)->id)->toBe(1);
});

test('by_id test', function () {
    expect(Product::all()->by_id(2)->title)->toBe("Second product");

    $products =  Product::all()->order_by('rand()');
    expect($products->by_id(3)->title)->toBe("Third product");
    expect($products->title)->toBe("Third product");
    expect($products->by_id(1)->title)->toBe("First product");
});


test('Stub and count test', function () {
    expect(Product::stub()->count)->toBe(0);
    expect(Product::stub()->count())->toBe(0);

    $products =  Product::all()->order_by('rand()')->stub();
    expect($products->count)->toBe(0);

    expect(Product::all()->find_by('id', 1)->stub->count)->toBe(0);
    expect(Product::all()->find_by('id', 1)->stub()->count)->toBe(0);
    expect(Product::all()->find_by('id', 1)->stub()->count())->toBe(0);
    expect(Product::all()->find_by('id', 1)->stub->count())->toBe(0);


    expect(Product::all()->find_by('id', 1)->ne)->toBe(true);
    expect(Product::all()->find_by('id', 1)->isNotEmpty)->toBe(true);
    expect(Product::all()->find_by('id', 1)->isEmpty)->toBe(false);

    expect(Product::all()->find_by('id', 9991)->ne)->toBe(false);
    expect(Product::all()->find_by('id', 9991)->isNotEmpty)->toBe(false);
    expect(Product::all()->find_by('id', 1999)->isEmpty)->toBe(true);
});

test('Plus and linked test', function () {
    expect(Product::all()->find_by('id', 1)->plus(Product::all()->where('id<3 and id > 1'))->count)->toBe(2);
});
