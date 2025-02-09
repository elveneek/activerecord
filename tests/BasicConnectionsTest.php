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
    if (!class_exists('Categories_to_product')) {
        class Categories_to_product extends \Elveneek\ActiveRecord {}
    }
    if (!class_exists('Brand')) {
        class Brand extends \Elveneek\ActiveRecord {}
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

test('linked connections', function () {
    $category = Category::find(1);
    
    expect($category->linked('categories_to_products')->count)->toBe(3);
    expect($category->_categories_to_products->count())->toBe(3);
    expect($category->_categories_to_products->_products->count())->toBe(3);
    
    expect(Product::all()->linked('brands')->count())->toBe(3);
    expect($category->_categories_to_products->_products->_brands->count())->toBe(2);
    expect($category->_categories_to_products->_products->_categories->_categories_to_products->_products->_brands->count())->toBe(3);
});

test('linked to_', function () {
    $category = Category::find(1);
    expect($category->to_products)->toBe('1,2,4');
});
