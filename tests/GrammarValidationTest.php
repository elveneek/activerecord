<?php

use Elveneek\Query\MySqlGrammar;

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }
});

test('quoteIdentifier wraps a simple name in backticks', function () {
    expect(MySqlGrammar::quoteIdentifier('id'))->toBe('`id`');
});

test('quoteIdentifier quotes each segment of a dotted name', function () {
    expect(MySqlGrammar::quoteIdentifier('products.id'))->toBe('`products`.`id`');
});

test('quoteIdentifier preserves a star segment within a dotted name', function () {
    expect(MySqlGrammar::quoteIdentifier('products.*'))->toBe('`products`.*');
});

test('quoteIdentifier rejects a bare star', function () {
    expect(fn () => MySqlGrammar::quoteIdentifier('*'))
        ->toThrow(\Elveneek\Exception\InvalidIdentifierException::class);
});

test('valid identifiers are accepted by assertIdentifier', function (string $identifier) {
    MySqlGrammar::assertIdentifier($identifier);
    expect(true)->toBeTrue();
})->with([
    'simple' => ['id'],
    'underscored' => ['category_id'],
    'dotted' => ['products.id'],
    'dotted star' => ['products.*'],
    'numeric suffix' => ['address_line_2'],
]);

test('invalid identifiers are rejected by quoteIdentifier', function (string $identifier) {
    expect(fn () => MySqlGrammar::quoteIdentifier($identifier))
        ->toThrow(\Elveneek\Exception\InvalidIdentifierException::class);
})->with([
    'empty' => [''],
    'space' => ['a b'],
    'quote' => ["a'b"],
    'semicolon' => ['a;b'],
    'double dash' => ['a--b'],
    'block comment' => ['a/*b'],
    'hash' => ['a#b'],
    'parenthesis' => ['a)b'],
    'leading digit' => ['1col'],
    'backtick' => ['a`b'],
    'comma list' => ['a,b'],
    'union' => ['a UNION SELECT'],
]);

test('compileSelectable rejects unsafe fragments', function (string $fragment) {
    $grammar = new MySqlGrammar();
    $reflection = new ReflectionMethod($grammar, 'compileSelectable');
    $reflection->setAccessible(true);

    expect(fn () => $reflection->invoke($grammar, $fragment))
        ->toThrow(\Elveneek\Exception\InvalidIdentifierException::class);
})->with([
    'semicolon fragment' => ['id; DROP'],
    'raw from' => ['title FROM products'],
    'or one equals one' => ["' OR '1'='1"],
    'aggregate without alias' => ['COUNT(*)'],
    'subselect' => ['(SELECT 1)'],
]);

test('compileSelectable accepts safe aliased aggregates', function () {
    $grammar = new MySqlGrammar();
    $reflection = new ReflectionMethod($grammar, 'compileSelectable');
    $reflection->setAccessible(true);

    expect($reflection->invoke($grammar, 'COUNT(*) AS total'))->toBe('COUNT(*) AS `total`')
        ->and($reflection->invoke($grammar, 'SUM(price) AS revenue'))->toBe('SUM(price) AS `revenue`')
        ->and($reflection->invoke($grammar, 'id AS row_id'))->toBe('`id` AS `row_id`');
});

test('compileSelectable accepts aggregate over a dotted column', function () {
    $grammar = new MySqlGrammar();
    $reflection = new ReflectionMethod($grammar, 'compileSelectable');
    $reflection->setAccessible(true);

    expect($reflection->invoke($grammar, 'MAX(products.price) AS max_price'))->toBe('MAX(products.price) AS `max_price`');
});

test('isSimpleIdentifier pattern accepts only well formed names', function (string $identifier, bool $expected) {
    $reflection = new ReflectionMethod(MySqlGrammar::class, 'isSimpleIdentifier');
    $reflection->setAccessible(true);

    expect($reflection->invoke(null, $identifier))->toBe($expected);
})->with([
    'id' => ['id', true],
    'category_id' => ['category_id', true],
    'products.id' => ['products.id', true],
    'products.*' => ['products.*', true],
    'bare star' => ['*', false],
    'COUNT(*)' => ['COUNT(*)', false],
    'id space' => ['id x', false],
    'id;' => ['id;', false],
]);

test('supported join operators are accepted', function (string $operator) {
    $compiled = (new MySqlGrammar())->compileSelect(
        (new \Elveneek\Query\QueryBuilder('products'))
            ->join('brands', 'products.brand_id', $operator, 'brands.id')
    );

    expect($compiled->sql)->toContain($operator);
})->with(['=', '!=', '<>', '<', '>', '<=', '>=']);

test('comparison operators recognised by the builder', function (string $operator) {
    $q = (new \Elveneek\Query\QueryBuilder('products'))->where('id', $operator, 1);

    expect($q->wheres[0]['operator'])->toBe($operator);
})->with(['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IS', 'IS NOT']);

test('column comparison operators are constrained', function (string $operator, bool $accepted) {
    $caught = false;
    try {
        (new \Elveneek\Query\QueryBuilder('products'))->whereColumn('a', $operator, 'b');
    } catch (\Elveneek\Exception\UnsupportedOperatorException $e) {
        $caught = true;
    } catch (\InvalidArgumentException $e) {
        $caught = true;
    }
    expect($caught)->toBe(!$accepted);
})->with([
    '=' => ['=', true],
    '!=' => ['!=', true],
    '<>' => ['<>', true],
    '<' => ['<', true],
    '>' => ['>', true],
    '<=' => ['<=', true],
    '>=' => ['>=', true],
    '<=>' => ['<=>', false],
    'LIKE' => ['LIKE', false],
]);
