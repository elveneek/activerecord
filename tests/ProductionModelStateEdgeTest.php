<?php

enum ProductionRecordState: string
{
    case Draft = 'draft';
    case Published = 'published';
}

final class ProductionPrefixCaster
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

class ProductionCastRecord extends \Elveneek\ActiveRecord
{
    protected static string $table = 'production_cast_records';
    protected static array $casts = [
        'id' => 'int',
        'decimal_value' => 'decimal:10',
        'float_value' => 'float',
        'is_enabled' => 'bool',
        'payload' => 'json',
        'event_at' => 'datetime',
        'event_date' => 'date',
        'state' => ProductionRecordState::class,
    ];

    public static function installCustomCaster(): void
    {
        static::$casts['custom_value'] = new ProductionPrefixCaster();
    }
}

class ProductionSchemaProduct extends \Elveneek\ActiveRecord
{
    protected static string $table = 'products';
}

class ProductionSerializationProduct extends \Elveneek\ActiveRecord
{
    protected static string $table = 'products';
    protected static array $appends = ['unstable_value'];
    public static bool $failSerialization = true;

    protected function getUnstableValue(): string
    {
        if (self::$failSerialization) {
            throw new RuntimeException('temporary accessor failure');
        }

        return 'recovered';
    }
}

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }

    $pdo = \Elveneek\ActiveRecord::connect();
    \Elveneek\DB::setConnection($pdo);
    $pdo->exec(file_get_contents(__DIR__ . '/data/mysql.sql'));
    $pdo->exec('DROP TABLE IF EXISTS production_cast_records');
    $pdo->exec(
        'CREATE TABLE production_cast_records ('
        . 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
        . 'decimal_value DECIMAL(30,10) NULL, '
        . 'float_value DOUBLE NULL, '
        . 'is_enabled TINYINT(1) NULL, '
        . 'payload JSON NULL, '
        . 'event_at DATETIME NULL, '
        . 'event_date DATE NULL, '
        . 'state VARCHAR(32) NULL, '
        . 'custom_value VARCHAR(255) NULL'
        . ') ENGINE=InnoDB'
    );

    ProductionCastRecord::installCustomCaster();
    ProductionSerializationProduct::$failSerialization = true;
    ProductionSchemaProduct::schemaMode(\Elveneek\SchemaMode::Evolve);
    \Elveneek\ActiveRecord::flushIdentityCache();
    \Elveneek\ActiveRecord::flushSchemaCache();
});

afterEach(function () {
    ProductionSchemaProduct::schemaMode(\Elveneek\SchemaMode::Evolve);
});

test('documented casts round-trip through the database', function () {
    $record = ProductionCastRecord::insert([
        'decimal_value' => '12.3400000000',
        'float_value' => 1.25,
        'is_enabled' => true,
        'payload' => ['nested' => ['value' => 7]],
        'event_at' => new DateTimeImmutable('2026-06-24 12:34:56'),
        'event_date' => new DateTimeImmutable('2026-06-24'),
        'state' => ProductionRecordState::Published,
        'custom_value' => 'value',
    ]);

    ProductionCastRecord::flushIdentityCache();
    $fresh = ProductionCastRecord::findOrFail($record->id);

    expect($fresh->id)->toBeInt()
        ->and($fresh->decimal_value)->toBe('12.3400000000')
        ->and($fresh->float_value)->toBeFloat()->toBe(1.25)
        ->and($fresh->is_enabled)->toBeTrue()
        ->and($fresh->payload)->toBe(['nested' => ['value' => 7]])
        ->and($fresh->event_at)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($fresh->event_at->format('Y-m-d H:i:s'))->toBe('2026-06-24 12:34:56')
        ->and($fresh->event_date->format('Y-m-d'))->toBe('2026-06-24')
        ->and($fresh->state)->toBe(ProductionRecordState::Published)
        ->and($fresh->custom_value)->toBe('read:stored:value');
});

test('decimal cast preserves values larger than native floating-point precision', function () {
    $exact = '12345678901234567890.1234567890';
    \Elveneek\ActiveRecord::$db->prepare(
        'INSERT INTO production_cast_records (decimal_value) VALUES (?)'
    )->execute([$exact]);

    expect(ProductionCastRecord::findOrFail(1)->decimal_value)->toBe($exact);
});

test('invalid json and backed enum values fail during hydration', function () {
    \Elveneek\ActiveRecord::$db->exec(
        "INSERT INTO production_cast_records (payload, state) VALUES ('{\"valid\":true}', 'not-a-state')"
    );

    expect(fn () => ProductionCastRecord::findOrFail(1)->state)
        ->toThrow(ValueError::class);

    \Elveneek\ActiveRecord::$db->exec('ALTER TABLE production_cast_records MODIFY payload TEXT NULL');
    \Elveneek\ActiveRecord::$db->exec("UPDATE production_cast_records SET state = 'draft', payload = 'not-json' WHERE id = 1");
    ProductionCastRecord::flushIdentityCache();
    ProductionCastRecord::flushSchemaCache();

    expect(fn () => ProductionCastRecord::findOrFail(1)->payload)
        ->toThrow(JsonException::class);
});

test('strict and suggest schema modes do not mutate production schema', function (\Elveneek\SchemaMode $mode) {
    ProductionSchemaProduct::schemaMode($mode);
    $product = ProductionSchemaProduct::create(['title' => 'Strict schema']);
    $product->unexpected_production_column = 'must not be created';

    expect(fn () => $product->save())->toThrow(RuntimeException::class);

    ProductionSchemaProduct::flushSchemaCache();
    expect(ProductionSchemaProduct::schemaColumns('products'))
        ->not->toHaveKey('unexpected_production_column');
})->with([
    'strict' => \Elveneek\SchemaMode::Strict,
    'suggest' => \Elveneek\SchemaMode::Suggest,
]);

test('evolve schema mode creates and persists a missing column', function () {
    ProductionSchemaProduct::schemaMode(\Elveneek\SchemaMode::Evolve);
    $product = ProductionSchemaProduct::create(['title' => 'Evolved schema']);
    $product->runtime_note = 'created by evolve mode';
    $product->save();

    ProductionSchemaProduct::flushIdentityCache();
    ProductionSchemaProduct::flushSchemaCache();

    expect(ProductionSchemaProduct::schemaColumns('products'))->toHaveKey('runtime_note')
        ->and(ProductionSchemaProduct::findOrFail($product->id)->runtime_note)->toBe('created by evolve mode');
});

test('serialization recursion guard is cleared when an accessor throws', function () {
    $product = ProductionSerializationProduct::findOrFail(1);

    expect(fn () => $product->toArray())
        ->toThrow(RuntimeException::class, 'temporary accessor failure');

    ProductionSerializationProduct::$failSerialization = false;
    $serialized = $product->toArray();

    expect($serialized)->toHaveKey('title', 'First product')
        ->and($serialized)->toHaveKey('unstable_value', 'recovered');
});

test('legacy scaffold rename_column renames a real column', function () {
    \Elveneek\ActiveRecord::$db->exec('DROP TABLE IF EXISTS production_legacy_schema');
    \Elveneek\ActiveRecord::$db->exec(
        'CREATE TABLE production_legacy_schema (id INT AUTO_INCREMENT PRIMARY KEY, old_title TEXT NULL) ENGINE=InnoDB'
    );

    \Elveneek\Scaffold::rename_column('production_legacy_schema', 'old_title', 'new_title');
    \Elveneek\ActiveRecord::flushSchemaCache();

    $columns = \Elveneek\ActiveRecord::schemaColumns('production_legacy_schema', true);
    expect($columns)->toHaveKey('new_title')->not->toHaveKey('old_title');
});
