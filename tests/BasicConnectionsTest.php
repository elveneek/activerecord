<?php

beforeAll(function () {
    Dotenv\Dotenv::createImmutable(__DIR__)->load();
    \Elveneek\ActiveRecord::$db = \Elveneek\ActiveRecord::connect();
    \Elveneek\ActiveRecord::$db->exec(file_get_contents(__DIR__ . '/data/mysql.sql'));

    if (!class_exists('Product')) {
        class Product extends \Elveneek\ActiveRecord {}
    }

    if (!class_exists('Category')) {
        class Category extends \Elveneek\ActiveRecord {}
    }
});


test('basic connections', function () {
    $product = Product::find(3);
    expect($product->title)->toBe("Third product");
    expect($product->category_id)->toBe(2);
    expect($product->category->id)->toBe(2);
    expect($product->category->title)->toBe('Second category');
    expect($product->category->id)->toBe(2);
    expect($product->category->id)->toBe(2); //Дубль - не ошибка, кейс реальный

    $category = Category::find(2);
    expect($category->products->count())->toBe(2);

    $products = $category->products;
    expect($products[0]->id)->toBe(3);
    expect($products[1]->id)->toBe(4);
});
/*
test('no existing relation', function () {
    $product = Product::find(5);
    expect($product->category)->toBeNull();
});
*/
test('plural to one', function () {
    $product = Product::find(2);
    expect($product->category->id)->toBe(1);
});

test('one to many', function () {
    $category = Category::find(1);
    expect($category->products->count())->toBe(3);
    expect($category->products[0]->id)->toBe(1);
});
