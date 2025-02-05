<?php

beforeAll(function () {
    Dotenv\Dotenv::createImmutable(__DIR__)->load();
    \Elveneek\ActiveRecord::$db = \Elveneek\ActiveRecord::connect();
});

if (!class_exists('Product')) {
    class Product extends Elveneek\ActiveRecord {
    }
}

test('pagination functionality', function () {
    // Create test table and insert sample data
    Elveneek\ActiveRecord::$db->exec("DROP TABLE IF EXISTS products");
    Elveneek\ActiveRecord::$db->exec("
        CREATE TABLE products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255),
            sort INT DEFAULT 0
        )
    ");
    
    // Insert 25 test records
    for ($i = 1; $i <= 25; $i++) {
        Elveneek\ActiveRecord::$db->exec("
            INSERT INTO products (title, sort) 
            VALUES ('Product {$i}', {$i})
        ");
    }

    // Test first page (10 items per page)
    $products = Product::all()->paginate(10, 0);
    expect(iterator_count($products))->toBe(10);
    expect($products->found_rows())->toBe(25);
    expect($products[0]->title)->toBe('Product 1');
    expect($products[9]->title)->toBe('Product 10');

    // Test second page
    $products = Product::all()->paginate(10, 1);
    expect(iterator_count($products))->toBe(10);
    expect($products->found_rows())->toBe(25);
    expect($products[0]->title)->toBe('Product 11');
    expect($products[9]->title)->toBe('Product 20');

    // Test last page (5 remaining items)
    $products = Product::all()->paginate(10, 2);
    expect(iterator_count($products))->toBe(5);
    expect($products->found_rows())->toBe(25);
    expect($products[0]->title)->toBe('Product 21');
    expect($products[4]->title)->toBe('Product 25');

    // Test with different items per page
    $products = Product::all()->paginate(5, 0);
    expect(iterator_count($products))->toBe(5);
    expect($products->found_rows())->toBe(25);
    expect($products[0]->title)->toBe('Product 1');
    expect($products[4]->title)->toBe('Product 5');

    // Test with where condition
    $products = Product::all()->where('id <= ?', 15)->paginate(10, 0);
    expect(iterator_count($products))->toBe(10);
    expect($products->found_rows())->toBe(15);

    // Test validation
    expect(fn() => Product::all()->paginate(0))->toThrow(\InvalidArgumentException::class);
    expect(fn() => Product::all()->paginate(10, -1))->toThrow(\InvalidArgumentException::class);

    // Cleanup
    Elveneek\ActiveRecord::$db->exec("DROP TABLE IF EXISTS products");
});
