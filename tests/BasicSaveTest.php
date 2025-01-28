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
     
    //expect(Product::f($id)->title)->toBe("new title");
     


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
