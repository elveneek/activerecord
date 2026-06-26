<?php

use Elveneek\Query\QueryBuilder;
use Elveneek\Query\MySqlGrammar;

beforeAll(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }
    \Elveneek\ActiveRecord::$db = \Elveneek\ActiveRecord::connect();

    if (!class_exists('Product')) {
        class Product extends \Elveneek\ActiveRecord {}
    }
    if (!class_exists('Category')) {
        class Category extends \Elveneek\ActiveRecord {}
    }
});

beforeEach(function () {
    \Elveneek\ActiveRecord::$db->exec(file_get_contents(__DIR__ . '/data/mysql.sql'));
    \Elveneek\ActiveRecord::flushIdentityCache();
    \Elveneek\ActiveRecord::flushSchemaCache();
});

test('quoteIdentifier rejects identifiers containing statement separators', function (string $identifier) {
    expect(fn () => MySqlGrammar::quoteIdentifier($identifier))
        ->toThrow(\Elveneek\Exception\InvalidIdentifierException::class);
})->with([
    'semicolon' => ['id; DROP TABLE products'],
    'comment double dash' => ['id--'],
    'hash comment' => ['id#x'],
    'block comment' => ['id/*evil*/'],
    'space inside' => ['id OR 1=1'],
    'single quote' => ["id' OR '1'='1"],
    'parenthesis' => ['id)'],
    'union keyword' => ['1 UNION SELECT password'],
    'backtick injection' => ['id` --'],
]);

test('assertIdentifier rejects non identifier input', function () {
    expect(fn () => MySqlGrammar::assertIdentifier('bad name'))
        ->toThrow(\Elveneek\Exception\InvalidIdentifierException::class)
        ->and(fn () => MySqlGrammar::assertIdentifier('id; DROP TABLE products'))
        ->toThrow(\Elveneek\Exception\InvalidIdentifierException::class);
});

test('assertIdentifier accepts valid simple and dotted names', function () {
    MySqlGrammar::assertIdentifier('id');
    MySqlGrammar::assertIdentifier('products.id');
    MySqlGrammar::assertIdentifier('products.*');

    expect(true)->toBeTrue();
});

test('select refuses SQL fragments and forces selectRaw instead', function () {
    expect(fn () => (new QueryBuilder('products'))->select('title FROM products')->toSql())
        ->toThrow(\Elveneek\Exception\InvalidIdentifierException::class);

    expect(fn () => (new QueryBuilder('products'))->select('id; DROP TABLE products')->toSql())
        ->toThrow(\Elveneek\Exception\InvalidIdentifierException::class);

    expect(fn () => (new QueryBuilder('products'))->select('COUNT(*)')->toSql())
        ->toThrow(\Elveneek\Exception\InvalidIdentifierException::class);
});

test('aliased select accepts only a safe base column or a simple aggregate', function () {
    expect(fn () => (new QueryBuilder('products'))->select('id AS exfiltrated --')->toSql())
        ->toThrow(\Elveneek\Exception\InvalidIdentifierException::class);

    $clean = (new QueryBuilder('products'))->select('COUNT(*) AS total')->toSql();
    expect($clean)->toContain('COUNT(*) AS `total`');
});

test('a non identifier first argument falls back to raw sql but still binds values', function () {
    $compiled = (new MySqlGrammar())->compileSelect((new QueryBuilder('products'))->where('id;--', 1));

    expect($compiled->bindings)->toBe([1])
        ->and($compiled->sql)->toContain('(id;--)');
});

test('where value is always bound as a parameter and never interpolated', function () {
    $injection = "1'; DROP TABLE products; --";
    $compiled = (new MySqlGrammar())->compileSelect((new QueryBuilder('products'))->where('id', $injection));

    expect($compiled->bindings)->toBe([$injection])
        ->and($compiled->sql)->not->toContain('DROP TABLE')
        ->and($compiled->sql)->toContain('?');
});

test('whereIn values are bound as parameters regardless of content', function () {
    $payload = ["a'); DROP TABLE x; --", 'b', 'c'];
    $compiled = (new MySqlGrammar())->compileSelect((new QueryBuilder('products'))->whereIn('id', $payload));

    expect($compiled->bindings)->toBe($payload)
        ->and($compiled->sql)->not->toContain('DROP TABLE');
});

test('orderBy rejects injected fragments', function () {
    expect(fn () => (new QueryBuilder('products'))->orderBy('id; DROP TABLE products'))
        ->toThrow(\Elveneek\Exception\InvalidIdentifierException::class)
        ->and(fn () => (new QueryBuilder('products'))->orderBy('id) UNION SELECT password'))
        ->toThrow(\Elveneek\Exception\InvalidIdentifierException::class);
});

test('groupBy validates identifiers at compile time while groupByRaw remains explicit', function () {
    expect(fn () => (new QueryBuilder('products'))->groupBy('id; DROP TABLE products')->toSql())
        ->toThrow(\Elveneek\Exception\InvalidIdentifierException::class);

    $explicit = (new MySqlGrammar())->compileSelect((new QueryBuilder('products'))->groupByRaw('YEAR(created_at)'));
    expect($explicit->sql)->toContain('YEAR(created_at)');
});

test('whereColumn refuses non identifier operands', function () {
    expect(fn () => (new QueryBuilder('products'))->whereColumn('a OR 1=1', '=', 'b'))
        ->toThrow(\Elveneek\Exception\InvalidIdentifierException::class);
});

test('join table and column arguments are validated', function () {
    expect(fn () => (new QueryBuilder('products'))->join('brands; DROP', 'a.id', '=', 'b.id')->toSql())
        ->toThrow(\Elveneek\Exception\InvalidIdentifierException::class);
});

test('join operator is constrained to a known set', function () {
    $q = (new QueryBuilder('products'))->join('brands', 'a.id', '=; DROP', 'b.id');
    expect(fn () => (new MySqlGrammar())->compileSelect($q))->toThrow(InvalidArgumentException::class);
});

test('joinSub alias must be a valid identifier', function () {
    $sub = (new QueryBuilder('brands'))->select('id');
    expect(fn () => (new QueryBuilder('products'))->joinSub($sub, 'b; DROP', 'a.id', '=', 'b.id'))
        ->toThrow(\Elveneek\Exception\InvalidIdentifierException::class);
});

test('invalid order direction is rejected', function () {
    expect(fn () => (new QueryBuilder('products'))->orderBy('id', 'sideways'))
        ->toThrow(InvalidArgumentException::class);
});

test('invalid limit and offset bounds are rejected', function () {
    expect(fn () => (new QueryBuilder('products'))->limit(-1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new QueryBuilder('products'))->offset(-5))->toThrow(InvalidArgumentException::class);
});

test('remember lifetime must be positive', function () {
    expect(fn () => (new QueryBuilder('products'))->remember(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new QueryBuilder('products'))->remember(-10))->toThrow(InvalidArgumentException::class);
});

test('a malicious runtime value is executed as data and cannot alter schema', function () {
    $payload = "x'; DROP TABLE categories; --";
    Product::create(['title' => $payload])->save();

    $fresh = Product::where('title', $payload)->firstOrFail();
    expect($fresh->title)->toBe($payload);

    $categories = \Elveneek\DB::connection()->query('SHOW TABLES LIKE "categories"')->fetchColumn();
    expect($categories)->not->toBeFalse();
});

test('delete removes only the targeted primary key row', function () {
    Product::findOrFail(1)->delete();

    expect(Product::all()->count())->toBe(4)
        ->and(Product::findOrFail(2)->id)->toBe(2);
});
