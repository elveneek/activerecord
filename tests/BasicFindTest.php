<?php

beforeAll(function () {
    Dotenv\Dotenv::createImmutable(__DIR__)->load();
    \Elveneek\ActiveRecord::$db = \Elveneek\ActiveRecord::connect();
    \Elveneek\ActiveRecord::$db->exec(file_get_contents(__DIR__ . '/data/mysql.sql'));

    class Product extends \Elveneek\ActiveRecord
    {
    }
    
    if (!class_exists('Category')) {
        class Category extends \Elveneek\ActiveRecord {}
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
    
    expect(\Elveneek\ActiveRecord::fromTable("products")->w("id", 1)->title)->toBe("First product");
});

test('by_id test', function () {
    expect(Product::all()->by_id(2)->title)->toBe("Second product");

    $products = Product::all()->order_by('rand()');
    expect($products->by_id(3)->title)->toBe("Third product");
    expect($products->title)->toBe("Third product");
    expect($products->by_id(1)->title)->toBe("First product");
});

test('Stub and count test', function () {
    expect(Product::stub()->count)->toBe(0);
    expect(Product::stub()->count())->toBe(0);

    $products = Product::all()->order_by('rand()')->stub();
    expect($products->count)->toBe(0);

    expect(Product::all()->find_by('id', 1)->stub->count)->toBe(0);
    expect(Product::all()->find_by('id', 1)->stub()->count)->toBe(0);
    expect(Product::all()->find_by('id', 1)->stub()->count())->toBe(0);
    expect(Product::all()->find_by('id', 1)->stub->count())->toBe(0);

    expect(Product::all()->find_by('id', 1)->ne)->toBe(true);
    expect(Product::all()->find_by('id', 1)->isNotEmpty)->toBe(true);
    expect(Product::all()->find_by('id', 1)->isEmpty)->toBe(false);

    expect(Product::all()->find_by('id', 1)->ne())->toBe(true);
    expect(Product::all()->find_by('id', 1)->isNotEmpty())->toBe(true);
    expect(Product::all()->find_by('id', 1)->isEmpty())->toBe(false);

    expect(Product::all()->find_by('id', 9991)->ne)->toBe(false);
    expect(Product::all()->find_by('id', 9991)->isNotEmpty)->toBe(false);
    expect(Product::all()->find_by('id', 1999)->isEmpty)->toBe(true);

    expect(Product::all()->find_by('id', 9991)->ne())->toBe(false);
    expect(Product::all()->find_by('id', 9991)->isNotEmpty())->toBe(false);
    expect(Product::all()->find_by('id', 1999)->isEmpty())->toBe(true);
});

test('Plus and linked test', function () {
    expect(Product::all()->find_by('id', 1)->plus(Product::all()->where('id<3 and id > 1'))->count)->toBe(2);
});

test('To array and json conversion', function () {
    expect(json_decode(Product::all()->select('id, title')->where('id IN (?)', [1,2,3])->to_json, true))
        ->toEqualCanonicalizing(json_decode('[{"id":1,"title":"First product"}, {"id":2,"title":"Second product"},{"id":3,"title":"Third product"}]', true));
    
    expect(Product::all()->select('id, title')->order_by('id desc')->where('id IN (?)', [1,2,3])->to_json)
        ->toBe('[{"id":3,"title":"Third product"},{"id":2,"title":"Second product"},{"id":1,"title":"First product"}]');
    
    expect(Product::all()->select('id, title')->where('id IN (?)', [1,2,3])->to_array)->toBe([
        ["id"=>1, "title"=> "First product"],
        ["id"=>2, "title"=> "Second product"],
        ["id"=>3, "title"=> "Third product"],
    ]);
});

/*
test('Tree structure test', function () {
    // Create test data
    \Elveneek\ActiveRecord::$db->exec("DROP TABLE categories");

    \Elveneek\ActiveRecord::$db->exec("
        CREATE TABLE categories (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(255),
            category_id INT NULL
        );
        INSERT INTO categories (id, title, category_id) VALUES
        (1, 'Root 1', NULL),
        (2, 'Root 2', NULL),
        (3, 'Child 1.1', 1),
        (4, 'Child 1.2', 1),
        (5, 'Child 2.1', 2),
        (6, 'Child 1.1.1', 3);
    ");

    // Test root level tree
    $tree = Category::all()->order_by('id')->tree();
    expect(count($tree))->toBe(2); // Two root nodes
    
    // Get first root node
 
    $firstRoot = $tree[0];
    expect($firstRoot)->toBeInstanceOf(Category::class);
    expect($firstRoot->title)->toBe('Root 1');
    
    // Get children
    $rootChildren = $firstRoot->queryTree;
    expect(count($rootChildren))->toBe(2); // Two children
    
 
    $firstChild = $rootChildren[0];
   
    $secondChild = $rootChildren[1];
    expect($firstChild)->toBeInstanceOf(Category::class);
    expect($secondChild)->toBeInstanceOf(Category::class);
    expect($firstChild->title)->toBe('Child 1.1');
    expect($secondChild->title)->toBe('Child 1.2');
    
    // Test subtree from specific root
    $subtree = Category::all()->order_by('id')->tree(1); // From Root 1
    expect(count($subtree))->toBe(2); // Two direct children
    
 
    $firstSubChild = $subtree[0];
    expect($firstSubChild)->toBeInstanceOf(Category::class);
    expect($firstSubChild->title)->toBe('Child 1.1');
    
    $grandChildren = $firstSubChild->queryTree;
    expect(count($grandChildren))->toBe(1); // One grandchild
    
 
    $grandChild = $grandChildren[0];
    expect($grandChild)->toBeInstanceOf(Category::class);
    expect($grandChild->title)->toBe('Child 1.1.1');
    
    // Test to_json_by_id
    $json = Category::all()->select('id, title')->where('id IN (?)', [1,2])->to_json_by_id();
    $data = json_decode($json, true);
    expect($data)->toHaveKey('1');
    expect($data)->toHaveKey('2');
    expect($data['1']['title'])->toBe('Root 1');
    expect($data['2']['title'])->toBe('Root 2');
    
    // Cleanup
    \Elveneek\ActiveRecord::$db->exec("DROP TABLE categories");
});

*/