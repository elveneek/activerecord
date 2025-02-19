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

test('native PHP empty() behavior', function () {
    $products = Product::all()->order_by('id');
    
    // empty() should return false for existing elements
    expect(empty($products[0]))->toBeFalse();
    expect(empty($products[1]))->toBeFalse();
    
    // empty() should return true for non-existent elements
    expect(empty($products[999]))->toBeTrue();
    expect(empty($products[-1]))->toBeTrue();
    
    // empty() should work with string keys
    $product = Product::find(1);
    expect(empty($product['title']))->toBeFalse(); // exists and not empty
    expect(empty($product['nonexistent']))->toBeTrue(); // doesn't exist
    expect(empty($product['menu_id']))->toBeTrue(); // exists but null
});

test('native PHP isset() behavior', function () {
    $products = Product::all()->order_by('id');
    
    // isset() should return true for existing elements
    expect(isset($products[0]))->toBeTrue();
    expect(isset($products[1]))->toBeTrue();
    
    // isset() should return false for non-existent elements
    expect(isset($products[999]))->toBeFalse();
    expect(isset($products[-1]))->toBeFalse();
    
    // isset() should work with string keys
    $product = Product::find(1);
    expect(isset($product['title']))->toBeTrue(); // exists
    expect(isset($product['nonexistent']))->toBeFalse(); // doesn't exist
    expect(isset($product['menu_id']))->toBeTrue(); // exists but null
});

test('native PHP array access behavior', function () {
    $products = Product::all()->order_by('id');
    
    // Array access should work with numeric indexes
    expect($products[0]->id)->toEqual(1);
    expect($products[1]->id)->toEqual(2);
    expect($products[2]->id)->toEqual(3);
    
    // Array access should work with string keys
    $product = Product::find(1);
    expect($product['title'])->toEqual('First product');
    expect($product['id'])->toEqual(1);
    
    // Array access should return null for non-existent elements
    expect($products[999])->toBeNull();
    expect($product['nonexistent'])->toBeNull();
});

test('native PHP array modification behavior', function () {
    $product = Product::find(1);
    
    // Setting values using array syntax
    $product['new_field'] = 'test value';
    expect($product['new_field'])->toEqual('test value');
    
    // Unsetting values using array syntax
    unset($product['new_field']);
    expect(isset($product['new_field']))->toBeFalse();
    
});

test('native PHP foreach behavior', function () {
    $products = Product::all()->order_by('id')->limit(3);
    
    $ids = [];
    foreach ($products as $key => $product) {
        $ids[$key] = $product->id;
    }
    
    expect($ids)->toEqual([
        0 => 1,
        1 => 2,
        2 => 3
    ]);
    
    // Test foreach on empty result
    $empty = Product::where('id > ?', 999);
    $count = 0;
    foreach ($empty as $item) {
        $count++;
    }
    expect($count)->toEqual(0);
});

test('native PHP count() behavior', function () {
    // Count should work on normal results
    expect(count(Product::all()))->toEqual(5);
    expect(count(Product::where('id <= ?', 3)))->toEqual(3);
    
    // Count should be 0 for empty results
    expect(count(Product::where('id > ?', 999)))->toEqual(0);
    
    // Count should work after modifications
    $products = Product::all();
    unset($products[0]);
    expect(count($products))->toEqual(4);


    //В отличие от предыдущего кейса, в данном случае проверяется что fetch_data выполняется без вызова count.
    $products = Product::all();
    expect(count($products))->toEqual(5);
    unset($products[0]);
    expect(count($products))->toEqual(4);
});

test('native PHP array edge cases', function () {
    $products = Product::all();
    
    // Accessing negative indexes
    expect(isset($products[-1]))->toBeFalse();
    expect(empty($products[-1]))->toBeTrue();
    
    // Accessing non-numeric indexes
    expect(isset($products['abc']))->toBeFalse();
    expect(empty($products['abc']))->toBeTrue();
    
    // Accessing beyond the end
    expect(isset($products[999]))->toBeFalse();
    expect(empty($products[999]))->toBeTrue();
    
    // Multiple iterations should work
    $ids1 = [];
    foreach ($products as $p) {
        $ids1[] = $p->id;
    }
    
    $ids2 = [];
    foreach ($products as $p) {
        $ids2[] = $p->id;
    }
    
    expect($ids1)->toEqual($ids2);
});

test('native PHP array type behavior', function () {
    $products = Product::all();
    
    // Should be traversable
    expect($products)->toBeInstanceOf(\Traversable::class);
    
    // Should implement array access
    expect($products)->toBeInstanceOf(\ArrayAccess::class);
    
    // Should be countable
    expect($products)->toBeInstanceOf(\Countable::class);
    
    // Should work with array functions
    expect(isset($products))->toBeTrue();
});

test('iterator rewind and multiple iterations', function () {
    $products = Product::all()->order_by('id')->limit(3);
    
    // First iteration
    $firstRun = [];
    foreach ($products as $key => $product) {
        $firstRun[$key] = $product->id;
    }
    
    // Second iteration should start from beginning due to rewind()
    $secondRun = [];
    foreach ($products as $key => $product) {
        $secondRun[$key] = $product->id;
    }
    
    // Both iterations should yield same results
    expect($firstRun)->toEqual([0 => 1, 1 => 2, 2 => 3]);
    expect($secondRun)->toEqual([0 => 1, 1 => 2, 2 => 3]);
    
    // After foreach, iterator should be at the end
    expect($products->valid())->toBeFalse();
    
    // Manual rewind should reset iterator
    $products->rewind();
    expect($products->valid())->toBeTrue();
    expect($products->current()->id)->toEqual(1);
    expect($products->key())->toEqual(0);
});

test('iterator state after foreach', function () {
    $products = Product::all()->order_by('id')->limit(2);
    
    // Initial state
    expect($products->valid())->toBeTrue();
    expect($products->current()->id)->toEqual(1);
    expect($products->key())->toEqual(0);
    
    // Complete iteration
    foreach ($products as $product) {
        // Just iterate
    }
    
    // After foreach, iterator should be at the end
    expect($products->valid())->toBeFalse();
    
    // Manual navigation
    $products->rewind();
    expect($products->valid())->toBeTrue();
    expect($products->current()->id)->toEqual(1);
    
    $products->next();
    expect($products->valid())->toBeTrue();
    expect($products->current()->id)->toEqual(2);
    
    $products->next();
    expect($products->valid())->toBeFalse();
});
