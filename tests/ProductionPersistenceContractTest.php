<?php

class ProductionPersistenceWidget extends \Elveneek\ActiveRecord
{
    protected static string $table = 'production_widgets';
    protected static array $fillable = ['title'];
    protected static array $casts = ['id' => 'int', 'quantity' => 'int'];
}

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }

    $pdo = \Elveneek\ActiveRecord::connect();
    \Elveneek\DB::setConnection($pdo);
    $pdo->exec('DROP TABLE IF EXISTS production_widgets');
    $pdo->exec(
        'CREATE TABLE production_widgets ('
        . 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
        . 'sku VARCHAR(64) NOT NULL, '
        . 'title VARCHAR(255) NULL, '
        . 'quantity INT NOT NULL DEFAULT 0, '
        . 'UNIQUE KEY production_widgets_sku_unique (sku)'
        . ') ENGINE=InnoDB'
    );

    \Elveneek\ActiveRecord::flushIdentityCache();
    \Elveneek\ActiveRecord::flushSchemaCache();
});

test('firstOrCreate returns existing row and creates a missing row', function () {
    $first = ProductionPersistenceWidget::firstOrCreate(
        ['sku' => 'one'],
        ['title' => 'Original', 'quantity' => 1],
    );
    $same = ProductionPersistenceWidget::firstOrCreate(
        ['sku' => 'one'],
        ['title' => 'Must not overwrite', 'quantity' => 9],
    );

    expect($same->id)->toBe($first->id)
        ->and($same->title)->toBe('Original')
        ->and(ProductionPersistenceWidget::all()->count())->toBe(1);
});

test('updateOrCreate updates existing row and creates a missing row', function () {
    $created = ProductionPersistenceWidget::updateOrCreate(
        ['sku' => 'one'],
        ['title' => 'Created', 'quantity' => 1],
    );
    $updated = ProductionPersistenceWidget::updateOrCreate(
        ['sku' => 'one'],
        ['title' => 'Updated', 'quantity' => 2],
    );
    $second = ProductionPersistenceWidget::updateOrCreate(
        ['sku' => 'two'],
        ['title' => 'Second', 'quantity' => 3],
    );

    expect($updated->id)->toBe($created->id)
        ->and($updated->title)->toBe('Updated')
        ->and($updated->quantity)->toBe(2)
        ->and($second->id)->not->toBe($created->id)
        ->and(ProductionPersistenceWidget::all()->count())->toBe(2);
});

test('upsert inserts and updates rows using the declared unique key', function () {
    $inserted = ProductionPersistenceWidget::upsert([
        ['sku' => 'one', 'title' => 'One', 'quantity' => 1],
        ['sku' => 'two', 'title' => 'Two', 'quantity' => 2],
    ], uniqueBy: ['sku'], update: ['title', 'quantity']);

    $updated = ProductionPersistenceWidget::upsert([
        ['sku' => 'one', 'title' => 'One updated', 'quantity' => 10],
        ['sku' => 'three', 'title' => 'Three', 'quantity' => 3],
    ], uniqueBy: ['sku'], update: ['title', 'quantity']);

    expect($inserted)->toBeGreaterThanOrEqual(2)
        ->and($updated)->toBeGreaterThanOrEqual(2)
        ->and(ProductionPersistenceWidget::orderBy('sku')->pluck('title'))
        ->toBe(['One updated', 'Three', 'Two'])
        ->and(ProductionPersistenceWidget::where('sku', 'one')->value('quantity'))->toBe(10);
});

test('upsert rejects rows with inconsistent column sets', function () {
    expect(fn () => ProductionPersistenceWidget::upsert([
        ['sku' => 'one', 'title' => 'One', 'quantity' => 1],
        ['sku' => 'two', 'quantity' => 2, 'unexpected' => 'ignored today'],
    ], uniqueBy: ['sku'], update: ['title', 'quantity']))
        ->toThrow(InvalidArgumentException::class);

    expect(ProductionPersistenceWidget::all()->count())->toBe(0);
});

test('upsert requires a non-empty conflict target', function () {
    expect(fn () => ProductionPersistenceWidget::upsert([
        ['sku' => 'one', 'title' => 'One', 'quantity' => 1],
    ], uniqueBy: [], update: ['title']))
        ->toThrow(InvalidArgumentException::class);

    expect(ProductionPersistenceWidget::all()->count())->toBe(0);
});

test('increment decrement and raw expressions retain bound values', function () {
    $widget = ProductionPersistenceWidget::insert([
        'sku' => 'counter',
        'title' => 'Counter',
        'quantity' => 5,
    ]);

    ProductionPersistenceWidget::where('id', $widget->id)->increment('quantity', 3);
    ProductionPersistenceWidget::where('id', $widget->id)->decrement('quantity', 2);
    ProductionPersistenceWidget::flushIdentityCache();

    expect(ProductionPersistenceWidget::findOrFail($widget->id)->quantity)->toBe(6);
});

test('saving a persisted projection without primary key is rejected', function () {
    $widget = ProductionPersistenceWidget::insert([
        'sku' => 'readonly',
        'title' => 'Read only',
        'quantity' => 1,
    ]);
    ProductionPersistenceWidget::flushIdentityCache();

    $projection = ProductionPersistenceWidget::select('title')->where('id', $widget->id)->firstOrFail();
    $projection->title = 'Cannot save';

    expect(fn () => $projection->save())
        ->toThrow(\Elveneek\Exception\ReadOnlyRecordException::class);
});

test('a dirty materialized result cannot silently become a different query', function () {
    ProductionPersistenceWidget::insertAll([
        ['sku' => 'one', 'title' => 'One', 'quantity' => 1],
        ['sku' => 'two', 'title' => 'Two', 'quantity' => 2],
    ]);

    $widgets = ProductionPersistenceWidget::orderBy('id')->load();
    $widgets[0]->title = 'Dirty';

    expect(fn () => $widgets->where('quantity', '>', 0))
        ->toThrow(\Elveneek\Exception\DirtyResultCannotBeRequeriedException::class);
});

test('fillable only affects fill and not explicitly trusted create attributes', function () {
    $widget = ProductionPersistenceWidget::create([
        'sku' => 'trusted',
        'title' => 'Created',
        'quantity' => 7,
    ])->fill([
        'title' => 'Filled',
        'quantity' => 99,
        'sku' => 'blocked',
    ])->save();

    ProductionPersistenceWidget::flushIdentityCache();
    $fresh = ProductionPersistenceWidget::findOrFail($widget->id);

    expect($fresh->sku)->toBe('trusted')
        ->and($fresh->title)->toBe('Filled')
        ->and($fresh->quantity)->toBe(7);
});
