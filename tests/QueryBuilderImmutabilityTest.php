<?php

use Elveneek\Query\QueryBuilder;
use Elveneek\Query\Expression;

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }
});

function baseQuery(): QueryBuilder
{
    return new QueryBuilder('products', null, 'id');
}

test('where returns a new instance and leaves the original unchanged', function () {
    $base = baseQuery();
    $derived = $base->where('category_id', 1);

    expect($derived)->not->toBe($base)
        ->and($base->wheres)->toBe([])
        ->and($derived->wheres)->toHaveCount(1);
});

test('orWhere chains without mutating the source', function () {
    $base = baseQuery()->where('id', 1);
    $either = $base->orWhere('id', 2);

    expect($base->wheres)->toHaveCount(1)
        ->and($either->wheres)->toHaveCount(2)
        ->and($either->wheres[1]['boolean'])->toBe('or');
});

test('select addSelect and selectRaw never mutate the caller', function () {
    $base = baseQuery();
    $selected = $base->select('id', 'title');
    $added = $selected->addSelect('text');
    $raw = $base->selectRaw('COUNT(*) AS c');

    expect($base->columns)->toBe([])
        ->and($selected->columns)->toBe(['id', 'title'])
        ->and($added->columns)->toBe(['id', 'title', 'text'])
        ->and($raw->columns)->toHaveCount(1)
        ->and($raw->columns[0])->toBeInstanceOf(Expression::class);
});

test('whereIn whereNull whereBetween keep the original pristine', function () {
    $base = baseQuery();
    $in = $base->whereIn('id', [1, 2]);
    $null = $base->whereNull('text');
    $between = $base->whereBetween('id', [1, 5]);

    expect($base->wheres)->toBe([])
        ->and($in->wheres)->toHaveCount(1)
        ->and($in->wheres[0]['type'])->toBe('in')
        ->and($null->wheres[0]['type'])->toBe('null')
        ->and($between->wheres[0]['type'])->toBe('between');
});

test('join groupBy having orderBy each return isolated instances', function () {
    $base = baseQuery();
    $joined = $base->join('brands', 'products.brand_id', '=', 'brands.id');
    $grouped = $base->groupBy('category_id');
    $having = $base->having('COUNT(*)', '>', 1);
    $ordered = $base->orderBy('id', 'desc');

    expect($base->joins)->toBe([])
        ->and($base->groups)->toBe([])
        ->and($base->havings)->toBe([])
        ->and($base->orders)->toBe([])
        ->and($joined->joins)->toHaveCount(1)
        ->and($grouped->groups)->toBe(['category_id'])
        ->and($having->havings)->toHaveCount(1)
        ->and($ordered->orders[0])->toBe(['column' => 'id', 'direction' => 'desc']);
});

test('limit and offset do not leak onto shared ancestors', function () {
    $base = baseQuery();
    $limited = $base->limit(10);
    $offset = $limited->offset(5);

    expect($base->limitValue)->toBeNull()
        ->and($base->offsetValue)->toBeNull()
        ->and($limited->limitValue)->toBe(10)
        ->and($limited->offsetValue)->toBeNull()
        ->and($offset->limitValue)->toBe(10)
        ->and($offset->offsetValue)->toBe(5);
});

test('distinct with and lock flags are instance-scoped', function () {
    $base = baseQuery();
    $distinct = $base->distinct();
    $locked = $base->lockForUpdate();
    $shared = $base->sharedLock();

    expect($base->distinctValue)->toBeFalse()
        ->and($distinct->distinctValue)->toBeTrue()
        ->and($base->lockMode)->toBeNull()
        ->and($locked->lockMode)->toBe('update')
        ->and($shared->lockMode)->toBe('share');
});

test('with eager loads accumulate only on derived instances', function () {
    $base = baseQuery();
    $one = $base->with('category');
    $two = $one->with('brand', 'category');

    expect($base->eagerLoads)->toBe([])
        ->and($one->eagerLoads)->toBe(['category'])
        ->and($two->eagerLoads)->toBe(['category', 'brand']);
});

test('cache related flags produce fresh instances without side effects', function () {
    $base = baseQuery();
    $remember = $base->remember(60, 'k');
    $forever = $base->rememberForever('k2');
    $withoutCache = $base->withoutCache();
    $withoutIdentity = $base->withoutIdentityMap();

    expect($base->rememberSeconds)->toBeNull()
        ->and($base->identityMapEnabled)->toBeTrue()
        ->and($remember->rememberSeconds)->toBe(60)
        ->and($remember->rememberKey)->toBe('k')
        ->and($forever->rememberSeconds)->toBe(PHP_INT_MAX)
        ->and($withoutCache->identityMapEnabled)->toBeFalse()
        ->and($withoutCache->rememberSeconds)->toBeNull()
        ->and($withoutIdentity->identityMapEnabled)->toBeFalse();
});

test('whereKey and whereKeys set lookupIds without polluting siblings', function () {
    $base = baseQuery();
    $single = $base->whereKey(3);
    $many = $base->whereKeys([1, 2, 3]);

    expect($base->lookupIds)->toBeNull()
        ->and($single->lookupIds)->toBe([3])
        ->and($many->lookupIds)->toBe([1, 2, 3]);
});

test('withoutLimitOffset and withoutOrder return isolated copies', function () {
    $base = baseQuery()->orderBy('id')->limit(5)->offset(2);
    $noOrder = $base->withoutOrder();
    $noLimit = $base->withoutLimitOffset();

    expect($base->orders)->toHaveCount(1)
        ->and($base->limitValue)->toBe(5)
        ->and($noOrder->orders)->toBe([])
        ->and($noOrder->limitValue)->toBe(5)
        ->and($noLimit->limitValue)->toBeNull()
        ->and($noLimit->offsetValue)->toBeNull();
});

test('derived branches do not interfere with each other', function () {
    $root = baseQuery()->where('category_id', 1);
    $a = $root->where('brand_id', 1);
    $b = $root->where('brand_id', 2);

    expect($root->wheres)->toHaveCount(1)
        ->and($a->wheres)->toHaveCount(2)
        ->and($b->wheres)->toHaveCount(2)
        ->and($a->wheres[1]['value'])->toBe(1)
        ->and($b->wheres[1]['value'])->toBe(2);
});

test('readonly constructor properties prevent accidental mutation', function () {
    $query = baseQuery();

    expect($query->table)->toBe('products')
        ->and($query->primaryKey)->toBe('id');

    try {
        $query->table = 'other';
    } catch (\Error $e) {
    }
    expect($query->table)->toBe('products');
});

test('fingerprint is stable for identical queries and differs when changed', function () {
    $a = baseQuery()->where('id', 1);
    $b = baseQuery()->where('id', 1);
    $c = baseQuery()->where('id', 2);

    expect($a->fingerprint())->toBe($b->fingerprint())
        ->and($a->fingerprint())->not->toBe($c->fingerprint());
});

test('dependencies accumulate across joins and subqueries immutably', function () {
    $sub = (new QueryBuilder('categories'))->select('id')->whereLike('title', 'First%');
    $base = baseQuery();
    $withSub = $base->whereIn('category_id', $sub);
    $compiled = (new \Elveneek\Query\MySqlGrammar())->compileSelect($withSub);

    expect($compiled->dependencies)->toContain('products')
        ->and($compiled->dependencies)->toContain('categories')
        ->and($base->wheres)->toBe([]);
});
