<?php

function as_test_badge($value, $field, $object): string
{
    return '[' . $field . ':' . strtoupper((string) $value) . ':' . $object->id . ']';
}

function as_test_priority($value, $field, $object): string
{
    return 'function';
}

class As_upper_title
{
    public static function call($value, $field, $object): string
    {
        return 'CLASS:' . strtoupper((string) $value) . ':' . $field . ':' . $object->id;
    }
}

class As_test_priority
{
    public static function call($value, $field, $object): string
    {
        return 'class';
    }
}

class As_invalid_formatter
{
    public function call($value, $field, $object): string
    {
        return 'invalid';
    }
}

class FormatterProduct extends \Elveneek\ActiveRecord
{
    protected static string $table = 'products';
}

beforeAll(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }
    \Elveneek\ActiveRecord::$db = \Elveneek\ActiveRecord::connect();
    \Elveneek\ActiveRecord::$db->exec(file_get_contents(__DIR__ . '/data/mysql.sql'));
    \Elveneek\ActiveRecord::flushIdentityCache();
    \Elveneek\ActiveRecord::flushSchemaCache();
});

test('_as_ formatted attribute calls a global function', function () {
    $result = FormatterProduct::find(1)->title_as_test_badge;

    expect($result)->toBe('[title:FIRST PRODUCT:1]');
});

test('_as_ formatted attribute calls a service class', function () {
    $result = FormatterProduct::find(1)->title_as_upper_title;

    expect($result)->toBe('CLASS:FIRST PRODUCT:title:1');
});

test('global formatter function has priority over service class', function () {
    expect(FormatterProduct::find(1)->title_as_test_priority)->toBe('function');
});

test('missing and invalid formatters produce descriptive errors', function () {
    expect(fn () => FormatterProduct::find(1)->title_as_missing_formatter)
        ->toThrow(\BadMethodCallException::class, 'define function as_missing_formatter() or class As_missing_formatter::call()')
        ->and(fn () => FormatterProduct::find(1)->title_as_invalid_formatter)
        ->toThrow(\BadMethodCallException::class, 'must be public and static');
});
