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
