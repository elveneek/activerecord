<?php

use Elveneek\Query\QueryBuilder;
use Elveneek\Query\MySqlGrammar;

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }
    if (!defined('SQL_NULL')) {
        define('SQL_NULL', '__ELVENEEK_SQL_NULL__');
    }
});

function wcompile(QueryBuilder $q): array
{
    $compiled = (new MySqlGrammar())->compileSelect($q);
    return [$compiled->sql, $compiled->bindings];
}

test('two argument where defaults to equals operator', function () {
    [$sql, $bindings] = wcompile((new QueryBuilder('products'))->where('category_id', 2));

    expect($sql)->toContain('`category_id` = ?')
        ->and($bindings)->toBe([2]);
});

test('three argument where honours the operator', function () {
    $q = (new QueryBuilder('products'))->where('id', '>=', 3);

    expect($q->wheres[0]['operator'])->toBe('>=')
        ->and($q->wheres[0]['value'])->toBe(3);
});

test('where with associative array chains conditions with AND', function () {
    [$sql, $bindings] = wcompile((new QueryBuilder('products'))->where(['category_id' => 1, 'brand_id' => 2]));

    expect($sql)->toContain('`category_id` = ? AND `brand_id` = ?')
        ->and($bindings)->toBe([1, 2]);
});

test('where with null value compiles to IS NULL', function () {
    [$sql, $bindings] = wcompile((new QueryBuilder('products'))->where('text', null));

    expect($sql)->toContain('`text` IS NULL')
        ->and($bindings)->toBe([]);
});

test('where with null and not-equal compiles to IS NOT NULL', function () {
    [$sql, $bindings] = wcompile((new QueryBuilder('products'))->where('text', '!=', null));

    expect($sql)->toContain('`text` IS NOT NULL')
        ->and($bindings)->toBe([]);
});

test('SQL_NULL constant normalises to a real null binding', function () {
    $q = (new QueryBuilder('products'))->where('text', SQL_NULL);

    expect($q->wheres[0]['type'])->toBe('null');
});

test('whereIn binds placeholders per value and keeps order', function () {
    [$sql, $bindings] = wcompile((new QueryBuilder('products'))->whereIn('id', [3, 1, 2]));

    expect($sql)->toContain('`id` IN (?, ?, ?)')
        ->and($bindings)->toBe([3, 1, 2]);
});

test('whereIn with an empty list compiles to an impossible clause', function () {
    [$sql, $bindings] = wcompile((new QueryBuilder('products'))->whereIn('id', []));

    expect($sql)->toContain('0 = 1')
        ->and($bindings)->toBe([]);
});

test('whereNotIn with an empty list compiles to a tautology', function () {
    [$sql, $bindings] = wcompile((new QueryBuilder('products'))->whereNotIn('id', []));

    expect($sql)->toContain('1 = 1')
        ->and($bindings)->toBe([]);
});

test('whereNotIn produces NOT IN with the same placeholder count', function () {
    [$sql, $bindings] = wcompile((new QueryBuilder('products'))->whereNotIn('id', [1, 2]));

    expect($sql)->toContain('`id` NOT IN (?, ?)')
        ->and($bindings)->toBe([1, 2]);
});

test('orWhereIn and orWhereNotIn attach with OR boolean', function () {
    $q = (new QueryBuilder('products'))
        ->where('id', 1)
        ->orWhereIn('id', [2, 3])
        ->orWhereNotIn('id', [4]);

    expect($q->wheres[1]['boolean'])->toBe('or')
        ->and($q->wheres[1]['type'])->toBe('in')
        ->and($q->wheres[2]['boolean'])->toBe('or')
        ->and($q->wheres[2]['not'])->toBeTrue();
});

test('whereBetween binds two range values', function () {
    [$sql, $bindings] = wcompile((new QueryBuilder('products'))->whereBetween('id', [2, 4]));

    expect($sql)->toContain('`id` BETWEEN ? AND ?')
        ->and($bindings)->toBe([2, 4]);
});

test('whereNotBetween inverts the predicate', function () {
    [$sql, $bindings] = wcompile((new QueryBuilder('products'))->whereNotBetween('id', [2, 4]));

    expect($sql)->toContain('`id` NOT BETWEEN ? AND ?')
        ->and($bindings)->toBe([2, 4]);
});

test('whereBetween requires exactly two values', function () {
    expect(fn () => (new QueryBuilder('products'))->whereBetween('id', [1]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new QueryBuilder('products'))->whereBetween('id', [1, 2, 3]))
        ->toThrow(InvalidArgumentException::class);
});

test('whereNull and whereNotNull emit IS NULL variants', function () {
    [$nullSql,] = wcompile((new QueryBuilder('products'))->whereNull('text'));
    [$notNullSql,] = wcompile((new QueryBuilder('products'))->whereNotNull('text'));

    expect($nullSql)->toContain('`text` IS NULL')
        ->and($notNullSql)->toContain('`text` IS NOT NULL');
});

test('orWhereNull and orWhereNotNull attach with OR', function () {
    $q = (new QueryBuilder('products'))
        ->where('id', 1)
        ->orWhereNull('text')
        ->orWhereNotNull('title');

    expect($q->wheres[1]['boolean'])->toBe('or')
        ->and($q->wheres[2]['boolean'])->toBe('or')
        ->and($q->wheres[2]['not'])->toBeTrue();
});

test('whereLike wraps the pattern in a LIKE comparison', function () {
    [$sql, $bindings] = wcompile((new QueryBuilder('products'))->whereLike('title', '%prod%'));

    expect($sql)->toContain('`title` LIKE ?')
        ->and($bindings)->toBe(['%prod%']);
});

test('orWhereLike attaches with OR boolean', function () {
    $q = (new QueryBuilder('products'))->where('id', 1)->orWhereLike('title', 'a%');

    expect($q->wheres[1]['boolean'])->toBe('or')
        ->and($q->wheres[1]['operator'])->toBe('LIKE');
});

test('whereColumn compares two columns without binding values', function () {
    [$sql, $bindings] = wcompile((new QueryBuilder('products'))->whereColumn('brand_id', '=', 'category_id'));

    expect($sql)->toContain('`brand_id` = `category_id`')
        ->and($bindings)->toBe([]);
});

test('whereColumn rejects an unsupported operator', function () {
    expect(fn () => (new QueryBuilder('products'))->whereColumn('a', '<=>', 'b'))
        ->toThrow(\Elveneek\Exception\UnsupportedOperatorException::class);
});

test('whereRaw preserves placeholders in declared order', function () {
    [$sql, $bindings] = wcompile((new QueryBuilder('products'))->whereRaw('id < ? AND id > ?', 5, 1));

    expect($sql)->toContain('(id < ? AND id > ?)')
        ->and($bindings)->toBe([5, 1]);
});

test('whereRaw expands an array binding into multiple placeholders', function () {
    [$sql, $bindings] = wcompile((new QueryBuilder('products'))->whereRaw('id IN (?)', [1, 2, 3]));

    expect($sql)->toContain('(id IN (?, ?, ?))')
        ->and($bindings)->toBe([1, 2, 3]);
});

test('whereRaw treats an empty array as NULL', function () {
    [$sql, $bindings] = wcompile((new QueryBuilder('products'))->whereRaw('id IN (?)', []));

    expect($sql)->toContain('(id IN (NULL))')
        ->and($bindings)->toBe([]);
});

test('whereExists embeds a subquery and forwards its bindings', function () {
    $sub = (new QueryBuilder('orders'))->select('id')->whereColumn('orders.product_id', '=', 'products.id');
    [$sql, $bindings] = wcompile((new QueryBuilder('products'))->whereExists($sub));

    expect($sql)->toContain('EXISTS (')
        ->and($sql)->toContain('FROM `orders`')
        ->and($bindings)->toBe([]);
});

test('whereNotExists prefixes NOT EXISTS', function () {
    $sub = (new QueryBuilder('orders'))->select('id')->whereColumn('orders.product_id', '=', 'products.id');
    [$sql,] = wcompile((new QueryBuilder('products'))->whereNotExists($sub));

    expect($sql)->toContain('NOT EXISTS (');
});

test('whereIn accepts a subquery builder', function () {
    $sub = (new QueryBuilder('categories'))->select('id')->where('id', '>', 0);
    [$sql, $bindings] = wcompile((new QueryBuilder('products'))->whereIn('category_id', $sub));

    expect($sql)->toContain('`category_id` IN (SELECT')
        ->and($bindings)->toBe([0]);
});

test('whereGroup nests predicates in parentheses', function () {
    [$sql, $bindings] = wcompile(
        (new QueryBuilder('products'))
            ->where('category_id', 1)
            ->whereGroup(function ($q) {
                $q->where('brand_id', 1)->orWhere('brand_id', 2);
            })
    );

    expect($sql)->toContain('(`brand_id` = ? OR `brand_id` = ?)')
        ->and($bindings)->toBe([1, 1, 2]);
});

test('orWhereGroup connects the group with OR', function () {
    $q = (new QueryBuilder('products'))
        ->where('id', 1)
        ->orWhereGroup(function ($q) {
            $q->where('brand_id', 1)->where('brand_id', 2);
        });

    expect($q->wheres[1]['boolean'])->toBe('or')
        ->and($q->wheres[1]['type'])->toBe('group');
});

test('a callable where is treated as an AND group', function () {
    [$sql,] = wcompile(
        (new QueryBuilder('products'))->where(function ($q) {
            $q->where('a', 1)->where('b', 2);
        })
    );

    expect($sql)->toContain('(`a` = ? AND `b` = ?)');
});

test('an empty group contributes no clause', function () {
    $q = (new QueryBuilder('products'))->where('id', 1)->whereGroup(function ($q) {});

    expect($q->wheres)->toHaveCount(1);
});

test('first predicate never receives a leading boolean keyword', function () {
    [$sql,] = wcompile((new QueryBuilder('products'))->where('id', 1)->where('id', 2));

    expect(str_starts_with(strstr($sql, 'WHERE'), 'WHERE AND'))->toBeFalse();
});

test('isOperator accepts documented operators and rejects others', function (string $operator, bool $accepted) {
    $reflection = new ReflectionMethod(QueryBuilder::class, 'isOperator');
    $reflection->setAccessible(true);

    expect($reflection->invoke(null, $operator))->toBe($accepted);
})->with([
    '=' => ['=', true],
    '!=' => ['!=', true],
    '<>' => ['<>', true],
    '<' => ['<', true],
    '>' => ['>', true],
    '<=' => ['<=', true],
    '>=' => ['>=', true],
    'LIKE' => ['LIKE', true],
    'NOT LIKE' => ['NOT LIKE', true],
    'IS' => ['IS', true],
    'IS NOT' => ['IS NOT', true],
    'like lowercased' => ['like', true],
    'arbitrary' => ['BANANA', false],
    'semicolon injection' => ['; DROP', false],
]);

test('a non operator second argument falls back to a raw predicate', function () {
    [$sql, $bindings] = wcompile((new QueryBuilder('products'))->where('id', 'BETWEEN', 1));

    expect($sql)->toContain('(id)')
        ->and($bindings)->toBe(['BETWEEN', 1]);
});
