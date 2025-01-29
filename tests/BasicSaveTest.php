<?php

beforeAll(function () {
    Dotenv\Dotenv::createImmutable(__DIR__)->load();
    \Elveneek\ActiveRecord::$db = \Elveneek\ActiveRecord::connect();
    \Elveneek\ActiveRecord::$db->exec(file_get_contents(__DIR__ . '/data/mysql.sql'));
});

test('basic save', function () {
    $product = Product::create();
    $product->title = "new product";
    $product->save();
    expect(Product::all()->where('title = "new product"')->title)->toBe("new product");
    
    $product = Product::all()->find_by('title', "new product");
    $id = $product->id;

    expect($id)->toBe(6);

    $product->title = "new title";
    $product->save();

    expect(Product::find_by('id', $id)->title)->toBe("new title");
    expect(Product::all()->f($id)->title)->toBe("new title");
});

test('batch save with saveAll', function() {
    // Создаем несколько тестовых продуктов
    for($i = 1; $i <= 3; $i++) {
        $product = Product::create();
        $product->title = "Product $i";
        $product->save();
    }

    // Получаем все продукты и меняем их названия
    $products = Product::all()->where('title LIKE "Product %"');
    foreach($products as $product) {
        $product->title = "Updated " . $product->title;
    }
    
    // Сохраняем все изменения одним запросом
    $products->saveAll();

    // Проверяем что все названия обновились
    $updatedProducts = Product::all()->where('title LIKE "Updated Product%"');
    expect($updatedProducts->count)->toBe(3);
    
    foreach($updatedProducts as $product) {
        expect($product->title)->toStartWith("Updated Product");
    }
});

test('multiple save in loop', function() {
    // Создаем несколько тестовых продуктов
    for($i = 1; $i <= 3; $i++) {
        $product = Product::create();
        $product->title = "Loop Product $i";
        $product->save();
    }

    // Обновляем каждый продукт отдельным save()
    foreach(Product::all()->where('title LIKE "Loop Product%"') as $product) {
        $product->title = "Modified " . $product->title;
        $product->save();
    }

    // Проверяем что все названия обновились
    $modifiedProducts = Product::all()->where('title LIKE "Modified Loop Product%"');
    expect($modifiedProducts->count)->toBe(3);
    
    foreach($modifiedProducts as $product) {
        expect($product->title)->toStartWith("Modified Loop Product");
    }
});

test('auto timestamp fields are set', function() {
    $product = Product::create();
    $product->title = "Timestamp Test";
    $product->save();
    
    $saved = Product::find($product->id);
    expect($saved->created_at)->not->toBeNull();
    expect($saved->updated_at)->not->toBeNull();
    
    $originalUpdatedAt = $saved->updated_at;
    sleep(1); // Wait to ensure timestamp will be different
    
    $saved->title = "Updated Timestamp Test";
    $saved->save();
    
    $updated = Product::find($saved->id);
    expect($updated->updated_at)->not->toBe($originalUpdatedAt);
    expect($updated->created_at)->toBe($saved->created_at);
});

test('auto-creation of missing columns', function() {
    $product = Product::create();
    $product->title = "Column Test";
    $product->new_dynamic_field = "Dynamic Value"; // This field doesn't exist yet
    $product->save();
    
    $saved = Product::find($product->id);
    expect($saved->new_dynamic_field)->toBe("Dynamic Value");
});

test('special handling of _id fields', function() {
    $product = Product::create();
    $product->title = "ID Field Test";
    $product->menu_id = 0;
    $product->save();
    
    $saved = Product::find($product->id);
    expect($saved->menu_id)->toBe(0); // Should preserve 0 value for _id fields
    
    $product = Product::create();
    $product->title = "ID Field Test 2";
    $product->menu_id = '0'; // String zero
    $product->save();
    
    $saved = Product::find($product->id);
    expect($saved->menu_id)->toBe(0); // Should convert string '0' to integer 0
    
    $product = Product::create();
    $product->title = "ID Field Test 3";
    $product->menu_id = 42; // Non-zero value
    $product->save();
    
    $saved = Product::find($product->id);
    expect($saved->menu_id)->toBe(42); // Should preserve non-zero values
});

test('batch update with saveAll preserves individual records', function() {
    // Create test products with different values
    $products = [];
    for($i = 1; $i <= 3; $i++) {
        $product = Product::create();
        $product->title = "Batch Test $i";
        $product->sort = $i * 10;
        $product->save();
        $products[] = $product->id;
    }
    
    // Update all products
    $collection = Product::all()->where('id IN (' . implode(',', $products) . ')');
    $collection->type = "batch-updated";
    $collection->saveAll();
    
    // Verify each record maintained its individual values while updating shared field
    foreach($products as $index => $id) {
        $product = Product::find($id);
        expect($product->title)->toBe("Batch Test " . ($index + 1));
        expect($product->sort)->toBe(($index + 1) * 10);
        expect($product->type)->toBe("batch-updated");
    }
});

test('save handles special SQL_NULL constant', function() {
    $product = Product::create();
    $product->title = "NULL Test";
    $product->text = SQL_NULL; // Using the special constant
    $product->save();
    
    $saved = Product::find($product->id);
    expect($saved->text)->toBeNull();
});

test('auto-increment sort field', function() {
    $product = Product::create();
    $product->title = "Sort Test";
    $product->save();
    
    $saved = Product::find($product->id);
    expect($saved->sort)->toBe($saved->id); // Sort should default to ID value
    
    $product = Product::create();
    $product->title = "Sort Test 2";
    $product->sort = 100; // Custom sort value
    $product->save();
    
    $saved = Product::find($product->id);
    expect($saved->sort)->toBe(100); // Should preserve custom sort value
});

test('save preserves field values after multiple operations', function() {
    
    
    $product = Product::create();
    $product->title = "Persistence Test";
    $product->text = "Original Text";
    $product->save();
    
    $id = $product->id;
  
    // Update one field
    $product->title = "Updated Title";
    $product->save();
    
    // Verify other fields remained unchanged
    $saved = Product::find($id);
    expect($saved->title)->toBe("Updated Title");
    expect($saved->text)->toBe("Original Text");
    
    // Update another field
    $product->text = "Updated Text";
    $product->save();
    
    // Verify all fields have correct values
    $saved = Product::find($id);
    expect($saved->title)->toBe("Updated Title");
    expect($saved->text)->toBe("Updated Text");
});
