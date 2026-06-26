<?php

use Elveneek\Query\QueryBuilder;
use Elveneek\Query\MySqlGrammar;
use Elveneek\Query\Expression;

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }
});

function cgrammar(): MySqlGrammar
{
    return new MySqlGrammar();
}

test('a bare select compiles to table star with backticks', function () {
    $compiled = cgrammar()->compileSelect(new QueryBuilder('products'));

    expect($compiled->sql)->toBe('SELECT `products`.* FROM `products`');
});

test('select with explicit columns quotes each identifier', function () {
    $compiled = cgrammar()->compileSelect((new QueryBuilder('products'))->select('id', 'title'));

    expect($compiled->sql)->toBe('SELECT `id`, `title` FROM `products`');
});

test('distinct prefixes the select list', function () {
    $compiled = cgrammar()->compileSelect((new QueryBuilder('products'))->select('category_id')->distinct());

    expect($compiled->sql)->toContain('SELECT DISTINCT `category_id`');
});

test('qualified column names keep each segment quoted', function () {
    $compiled = cgrammar()->compileSelect((new QueryBuilder('products'))->select('products.id'));

    expect($compiled->sql)->toContain('`products`.`id`');
});

test('select supports aliased aggregate expressions', function () {
    $compiled = cgrammar()->compileSelect((new QueryBuilder('products'))->select('COUNT(*) AS total'));

    expect($compiled->sql)->toContain('COUNT(*) AS `total`');
});

test('selectRaw preserves raw fragments and forwards bindings', function () {
    $compiled = cgrammar()->compileSelect((new QueryBuilder('products'))->selectRaw('UPPER(title) AS upper_title'));

    expect($compiled->sql)->toContain('UPPER(title) AS upper_title')
        ->and($compiled->bindings)->toBe([]);
});

test('addSelect merges with the existing column list', function () {
    $compiled = cgrammar()->compileSelect((new QueryBuilder('products'))->select('id')->addSelect('title'));

    expect($compiled->sql)->toBe('SELECT `id`, `title` FROM `products`');
});

test('inner join compiles with ON clause and quoted identifiers', function () {
    $compiled = cgrammar()->compileSelect(
        (new QueryBuilder('products'))->join('brands', 'products.brand_id', '=', 'brands.id')
    );

    expect($compiled->sql)->toContain('INNER JOIN `brands` ON `products`.`brand_id` = `brands`.`id`')
        ->and($compiled->dependencies)->toContain('brands');
});

test('leftJoin rightJoin and crossJoin map to their SQL keyword', function () {
    $left = cgrammar()->compileSelect((new QueryBuilder('products'))->leftJoin('brands', 'products.brand_id', '=', 'brands.id'));
    $right = cgrammar()->compileSelect((new QueryBuilder('products'))->rightJoin('brands', 'products.brand_id', '=', 'brands.id'));
    $cross = cgrammar()->compileSelect((new QueryBuilder('products'))->crossJoin('numbers'));

    expect($left->sql)->toContain('LEFT JOIN `brands`')
        ->and($right->sql)->toContain('RIGHT JOIN `brands`')
        ->and($cross->sql)->toContain('CROSS JOIN `numbers`')
        ->and($cross->sql)->not->toContain(' ON ');
});

test('a join alias emits AS and validates both identifiers', function () {
    $compiled = cgrammar()->compileSelect(
        (new QueryBuilder('products'))->join('brands', 'b.id', '=', 'products.brand_id', 'inner', 'b')
    );

    expect($compiled->sql)->toContain('INNER JOIN `brands` AS `b` ON `b`.`id` = `products`.`brand_id`');
});

test('a non-cross join without both columns is rejected', function () {
    expect(fn () => cgrammar()->compileSelect((new QueryBuilder('products'))->join('brands', null, '=', null)))
        ->toThrow(InvalidArgumentException::class);
});

test('an unsupported join type is rejected', function () {
    $q = (new QueryBuilder('products'))->join('brands', 'a.id', '=', 'b.id', 'OUTER');
    expect(fn () => cgrammar()->compileSelect($q))->toThrow(InvalidArgumentException::class);
});

test('joinSub embeds a compiled subquery with bindings', function () {
    $sub = (new QueryBuilder('brands'))->select('id', 'title')->where('id', '>', 0);
    $compiled = cgrammar()->compileSelect(
        (new QueryBuilder('products'))->joinSub($sub, 'b', 'b.id', '=', 'products.brand_id')
    );

    expect($compiled->sql)->toContain('INNER JOIN (SELECT')
        ->and($compiled->sql)->toContain(') AS `b`')
        ->and($compiled->bindings)->toContain(0)
        ->and($compiled->dependencies)->toContain('brands');
});

test('groupBy quotes identifiers and supports raw expressions', function () {
    $compiled = cgrammar()->compileSelect((new QueryBuilder('products'))->groupBy('category_id')->groupByRaw('YEAR(created_at)'));

    expect($compiled->sql)->toContain('GROUP BY `category_id`, YEAR(created_at)');
});

test('havingRaw compiles a raw predicate after group by', function () {
    $compiled = cgrammar()->compileSelect(
        (new QueryBuilder('products'))
            ->groupBy('category_id')
            ->havingRaw('COUNT(*) > ?', 2)
    );

    expect($compiled->sql)->toContain('HAVING (COUNT(*) > ?)')
        ->and($compiled->bindings)->toContain(2);
});

test('havingRaw preserves the raw sql', function () {
    $compiled = cgrammar()->compileSelect(
        (new QueryBuilder('products'))
            ->groupBy('category_id')
            ->havingRaw('SUM(sort) > ?', 10)
    );

    expect($compiled->sql)->toContain('HAVING (SUM(sort) > ?)')
        ->and($compiled->bindings)->toContain(10);
});

test('orderBy quotes and uppercases the direction', function () {
    $compiled = cgrammar()->compileSelect((new QueryBuilder('products'))->orderBy('id', 'desc'));

    expect($compiled->sql)->toContain('ORDER BY `id` DESC');
});

test('orderByRaw preserves arbitrary sql', function () {
    $compiled = cgrammar()->compileSelect((new QueryBuilder('products'))->orderByRaw('RAND()'));

    expect($compiled->sql)->toContain('ORDER BY RAND()');
});

test('limit and offset compile in order', function () {
    $compiled = cgrammar()->compileSelect((new QueryBuilder('products'))->limit(10)->offset(20));

    expect($compiled->sql)->toContain('LIMIT 10 OFFSET 20');
});

test('offset without limit falls back to a huge limit', function () {
    $compiled = cgrammar()->compileSelect((new QueryBuilder('products'))->offset(5));

    expect($compiled->sql)->toContain('LIMIT 18446744073709551615 OFFSET 5');
});

test('lock modes append the trailing locking clause', function () {
    $update = cgrammar()->compileSelect((new QueryBuilder('products'))->where('id', 1)->lockForUpdate());
    $share = cgrammar()->compileSelect((new QueryBuilder('products'))->where('id', 1)->sharedLock());

    expect($update->sql)->toEndWith('FOR UPDATE')
        ->and($share->sql)->toEndWith('LOCK IN SHARE MODE');
});

test('compileCount wraps the source query without ordering', function () {
    $compiled = cgrammar()->compileCount((new QueryBuilder('products'))->orderBy('id')->limit(5));

    expect($compiled->sql)->toStartWith('SELECT COUNT(*) AS aggregate FROM (')
        ->and($compiled->sql)->not->toContain('ORDER BY');
});

test('compileCount ignores limit when asked', function () {
    $q = (new QueryBuilder('products'))->limit(5);
    $ignored = cgrammar()->compileCount($q, true);
    $respected = cgrammar()->compileCount($q, false);

    expect($ignored->sql)->not->toContain('LIMIT')
        ->and($respected->sql)->toContain('LIMIT 5');
});

test('binding types mirror the runtime values', function () {
    $compiled = cgrammar()->compileSelect(
        (new QueryBuilder('products'))->where('id', 1)->where('is_active', true)
    );

    expect($compiled->bindingTypes)->toBe([\PDO::PARAM_INT, \PDO::PARAM_BOOL]);
});

test('a null raw binding produces a null parameter type', function () {
    $compiled = cgrammar()->compileSelect(
        (new QueryBuilder('products'))->whereRaw('text = ?', null)
    );

    expect($compiled->bindings)->toBe([null])
        ->and($compiled->bindingTypes)->toBe([\PDO::PARAM_NULL]);
});

test('dependencies are unique across joins and subquery predicates', function () {
    $sub = (new QueryBuilder('categories'))->select('id')->where('id', '>', 0);
    $compiled = cgrammar()->compileSelect(
        (new QueryBuilder('products'))
            ->join('brands', 'products.brand_id', '=', 'brands.id')
            ->whereIn('category_id', $sub)
    );

    expect($compiled->dependencies)->toBe(['products', 'brands', 'categories']);
});

test('an unknown predicate type throws during compilation', function () {
    $reflection = new ReflectionMethod(QueryBuilder::class, 'appendWhere');
    $reflection->setAccessible(true);
    $q = $reflection->invoke(new QueryBuilder('products'), ['type' => 'mystery', 'boolean' => 'and']);

    expect(fn () => cgrammar()->compileSelect($q))->toThrow(InvalidArgumentException::class);
});
