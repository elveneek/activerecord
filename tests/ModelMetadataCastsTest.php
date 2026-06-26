<?php

use Elveneek\Metadata\ModelMetadata;

enum CastStatus: string
{
    case Active = 'active';
    case Banned = 'banned';
}

final class CastPrefix
{
    public function get(mixed $value): string
    {
        return 'read:' . $value;
    }

    public function set(mixed $value): string
    {
        return 'stored:' . $value;
    }
}

class CastDemo extends \Elveneek\ActiveRecord
{
    protected static string $table = 'cast_demos';
    protected static array $casts = [
        'id' => 'int',
        'count' => 'integer',
        'ratio' => 'float',
        'price' => 'double',
        'is_active' => 'bool',
        'flag' => 'boolean',
        'name' => 'string',
        'payload' => 'json',
        'tags' => 'array',
        'amount' => 'decimal:2',
        'money' => 'decimal:4',
        'status' => CastStatus::class,
    ];

    public static function installCustomCaster(): void
    {
        static::$casts['custom'] = new CastPrefix();
    }
}

class CastConvention extends \Elveneek\ActiveRecord
{
    protected static string $table = 'cast_conventions';
}

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }
    CastDemo::installCustomCaster();
});

function meta(string $class): ModelMetadata
{
    return new ModelMetadata($class);
}

test('integer casts coerce database strings into integers both ways', function () {
    $m = meta(CastDemo::class);

    expect($m->castFromDatabase('count', '7'))->toBe(7)
        ->and($m->castForDatabase('count', 7))->toBe(7);
});

test('float and double casts produce real numbers', function () {
    $m = meta(CastDemo::class);

    expect($m->castFromDatabase('ratio', '1.5'))->toBeFloat()->toBe(1.5)
        ->and($m->castFromDatabase('price', '9.99'))->toBeFloat()->toBe(9.99);
});

test('boolean casts interpret truthy database values', function () {
    $m = meta(CastDemo::class);

    expect($m->castFromDatabase('is_active', 1))->toBeTrue()
        ->and($m->castFromDatabase('is_active', 0))->toBeFalse()
        ->and($m->castFromDatabase('flag', '1'))->toBeTrue();
});

test('string casts force scalar values to strings', function () {
    $m = meta(CastDemo::class);

    expect($m->castFromDatabase('name', 42))->toBe('42')
        ->and($m->castForDatabase('name', 42))->toBe('42');
});

test('json casts encode and decode structures', function () {
    $m = meta(CastDemo::class);

    $decoded = $m->castFromDatabase('payload', '{"a":1}');
    expect($decoded)->toBe(['a' => 1]);

    $encoded = $m->castForDatabase('payload', ['a' => 1]);
    expect($encoded)->toBe('{"a":1}');
});

test('array cast behaves like json', function () {
    $m = meta(CastDemo::class);

    expect($m->castFromDatabase('tags', '["x","y"]'))->toBe(['x', 'y'])
        ->and($m->castForDatabase('tags', ['x', 'y']))->toBe('["x","y"]');
});

test('null values pass through every cast untouched', function () {
    $m = meta(CastDemo::class);

    foreach (['count', 'ratio', 'is_active', 'name', 'payload', 'amount', 'status'] as $field) {
        expect($m->castFromDatabase($field, null))->toBeNull()
            ->and($m->castForDatabase($field, null))->toBeNull();
    }
});

test('decimal cast rounds half up to the configured scale', function () {
    $m = meta(CastDemo::class);

    expect($m->castFromDatabase('amount', '1.234'))->toBe('1.23')
        ->and($m->castFromDatabase('amount', '1.235'))->toBe('1.24')
        ->and($m->castFromDatabase('amount', '1.005'))->toBe('1.01')
        ->and($m->castFromDatabase('amount', '1.999'))->toBe('2.00');
});

test('decimal cast preserves scale even for integer inputs', function () {
    $m = meta(CastDemo::class);

    expect($m->castFromDatabase('amount', '5'))->toBe('5.00')
        ->and($m->castFromDatabase('money', '3'))->toBe('3.0000');
});

test('decimal cast handles large numbers without float drift', function () {
    $m = meta(CastDemo::class);
    $big = '12345678901234567890.1234';

    expect($m->castFromDatabase('money', $big))->toBe('12345678901234567890.1234');
});

test('decimal cast pads short fractions and rounds negatives', function () {
    $m = meta(CastDemo::class);

    expect($m->castFromDatabase('money', '1.5'))->toBe('1.5000')
        ->and($m->castFromDatabase('amount', '-1.235'))->toBe('-1.24')
        ->and($m->castFromDatabase('amount', '-0.004'))->toBe('0.00');
});

test('decimal cast accepts float inputs and reformats them', function () {
    $m = meta(CastDemo::class);

    expect($m->castForDatabase('amount', 12.3456))->toBe('12.35');
});

test('backed enum cast resolves from the database value', function () {
    $m = meta(CastDemo::class);

    expect($m->castFromDatabase('status', 'active'))->toBe(CastStatus::Active)
        ->and($m->castForDatabase('status', CastStatus::Banned))->toBe('banned');
});

test('custom caster object runs get and set with the value', function () {
    $m = meta(CastDemo::class);

    expect($m->castForDatabase('custom', 'v'))->toBe('stored:v')
        ->and($m->castFromDatabase('custom', 'stored:v'))->toBe('read:stored:v');
});

test('conventional casts make foreign keys integer and is_ fields boolean', function () {
    $m = meta(CastConvention::class);

    expect($m->castFromDatabase('category_id', '5'))->toBeInt()->toBe(5)
        ->and($m->castFromDatabase('is_published', 1))->toBeBool()->toBeTrue()
        ->and($m->castFromDatabase('id', '9'))->toBeInt()->toBe(9);
});

test('unconfigured fields are returned unchanged', function () {
    $m = meta(CastConvention::class);

    expect($m->castFromDatabase('description', 'raw'))->toBe('raw')
        ->and($m->castForDatabase('description', 'raw'))->toBe('raw');
});

test('table name is derived from the class name via snake and plural', function () {
    expect(meta(CastDemo::class)->table())->toBe('cast_demos')
        ->and(meta(CastConvention::class)->table())->toBe('cast_conventions');
});

test('primaryKey defaults to id and can be overridden', function () {
    expect(meta(CastConvention::class)->primaryKey())->toBe('id');
});

test('casts hidden visible and appends default to empty arrays', function () {
    $m = meta(CastConvention::class);

    expect($m->casts())->toBe([])
        ->and($m->hidden())->toBe([])
        ->and($m->visible())->toBe([])
        ->and($m->appends())->toBe([]);
});

test('invalid decimal scale is rejected', function () {
    $m = meta(CastDemo::class);

    expect(fn () => $m->castFromDatabase('amount', 'x.y.z'))->toThrow(\InvalidArgumentException::class);
});
