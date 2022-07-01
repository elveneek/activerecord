<?php



beforeAll(function () {
    echo 'beforeAll';
    Dotenv\Dotenv::createImmutable(__DIR__)->load();
    \Elveneek\ActiveRecord::$db = \Elveneek\ActiveRecord::connect();
    \Elveneek\ActiveRecord::$db->exec(file_get_contents(__DIR__ . '/data/mysql.sql'));

    class Product extends \Elveneek\ActiveRecord {}
});


test('basic find', function () {
    expect(Product::find(2)->id)->toBe(2);
    expect(Product::where('id <> 3')->where('id<5 and id > 2')->id)->toBe(4);
    expect(Product::all()->where('id<3 and id > 1')->id )->toBe(2);
    expect(Product::where('id<3 and id > 1')->id)->toBe(2);
    expect(Product::where('id<3')->where('id > 1')->id)->toBe(2);
    expect(Product::all()->where('id<3 and id > 1')->id)->toBe(2);
    expect(Product::find_by('id',2)->id)->toBe(2);
    expect(Product::all()->find_by('id',2)->id)->toBe(2);
    expect(Product::all()->count)->toBe(5);
});
