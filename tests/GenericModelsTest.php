<?php

class Generic_product
{
}

class GenericCategory extends \Elveneek\ActiveRecord
{
    protected static string $table = 'generic_categories';
}

class GenericProductRecord extends \Elveneek\ActiveRecord
{
    public function decoratedTitle(): string
    {
        return 'mapped:' . $this->title;
    }
}

beforeEach(function () {
    \Elveneek\ActiveRecord::clearTableMap();
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }

    $pdo = \Elveneek\ActiveRecord::connect();
    \Elveneek\DB::setConnection($pdo);
    $pdo->exec('DROP TABLE IF EXISTS generic_products');
    $pdo->exec('DROP TABLE IF EXISTS generic_categories');
    $pdo->exec(
        'CREATE TABLE generic_categories ('
        . 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
        . 'generic_product_id INT NULL, '
        . 'title VARCHAR(255) NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8'
    );
    $pdo->exec(
        'CREATE TABLE generic_products ('
        . 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
        . 'generic_category_id INT NULL, '
        . 'title VARCHAR(255) NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8'
    );
    $pdo->exec("INSERT INTO generic_categories (id, generic_product_id, title) VALUES (1, 1, 'Generic category')");
    $pdo->exec("INSERT INTO generic_products (id, generic_category_id, title) VALUES (1, 1, 'Generic product'), (2, 1, 'Second generic product')");

    \Elveneek\ActiveRecord::flushIdentityCache();
    \Elveneek\ActiveRecord::flushSchemaCache();
    \Elveneek\DB::flushQueryLog();
});

afterEach(function () {
    \Elveneek\ActiveRecord::clearTableMap();
});

test('relations fall back to a generic table model when the conventional class is foreign', function () {
    $title = GenericCategory::find(1)->generic_products->limit(1)->title;

    expect($title)->toBe('Generic product')
        ->and(GenericCategory::find(1)->generic_products)->toBeInstanceOf(\Elveneek\TableRecord::class);
});

test('fromTable can traverse generic relations without declaring model classes', function () {
    $title = \Elveneek\ActiveRecord::fromTable('generic_categories')
        ->find(1)
        ->generic_products
        ->limit(1)
        ->title;

    expect($title)->toBe('Generic product');
});

test('generic table models can be the source side of inferred relations', function () {
    $title = \Elveneek\ActiveRecord::fromTable('generic_products')
        ->find(1)
        ->generic_categories
        ->limit(1)
        ->title;

    expect($title)->toBe('Generic category');
});

test('mapTable binds a table to an explicit ActiveRecord class', function () {
    \Elveneek\ActiveRecord::mapTable('generic_products', GenericProductRecord::class);

    $product = \Elveneek\ActiveRecord::fromTable('generic_products')->findOrFail(1);

    expect($product)->toBeInstanceOf(GenericProductRecord::class)
        ->and($product->table)->toBe('generic_products')
        ->and($product->decoratedTitle())->toBe('mapped:Generic product');
});

test('relations prefer an explicit table map over the generic fallback', function () {
    \Elveneek\ActiveRecord::mapTable('generic_products', GenericProductRecord::class);

    $product = GenericCategory::find(1)->generic_products->limit(1);

    expect($product)->toBeInstanceOf(GenericProductRecord::class)
        ->and($product->decoratedTitle())->toBe('mapped:Generic product');
});

test('generic table models keep identity map state separated by table', function () {
    $category = \Elveneek\ActiveRecord::fromTable('generic_categories')->findOrFail(1);
    $product = \Elveneek\ActiveRecord::fromTable('generic_products')->findOrFail(1);

    expect($category)->toBeInstanceOf(\Elveneek\TableRecord::class)
        ->and($product)->toBeInstanceOf(\Elveneek\TableRecord::class)
        ->and($category->title)->toBe('Generic category')
        ->and($product->title)->toBe('Generic product');
});