<?php

class PersistenceItem extends \Elveneek\ActiveRecord
{
    protected static string $table = 'persistence_items';
    protected static array $fillable = ['title', 'quantity', 'note'];
    protected static array $casts = ['id' => 'int', 'quantity' => 'int'];
    protected static ?string $versionColumn = 'lock_version';
}

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }
    $pdo = \Elveneek\ActiveRecord::connect();
    \Elveneek\DB::setConnection($pdo);
    $pdo->exec('DROP TABLE IF EXISTS persistence_items');
    $pdo->exec(
        'CREATE TABLE persistence_items ('
        . 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
        . 'sku VARCHAR(64) NOT NULL, '
        . 'title VARCHAR(255) NULL, '
        . 'quantity INT NOT NULL DEFAULT 0, '
        . 'note VARCHAR(255) NULL, '
        . 'lock_version INT NOT NULL DEFAULT 0, '
        . 'created_at DATETIME NULL, '
        . 'updated_at DATETIME NULL, '
        . 'UNIQUE KEY persistence_items_sku_unique (sku)'
        . ') ENGINE=InnoDB'
    );
    \Elveneek\ActiveRecord::flushIdentityCache();
    \Elveneek\ActiveRecord::flushSchemaCache();
});

test('create builds a new record without persisting until save', function () {
    $item = PersistenceItem::create(['sku' => 'a', 'title' => 'Pending']);

    expect($item->isNew())->toBeTrue()
        ->and($item->id)->toBeNull()
        ->and(PersistenceItem::all()->count())->toBe(0);
});

test('insert persists immediately and returns the saved model', function () {
    $item = PersistenceItem::insert(['sku' => 'a', 'title' => 'Stored']);

    expect($item->id)->not->toBeNull()
        ->and($item->isNew())->toBeFalse()
        ->and(PersistenceItem::all()->count())->toBe(1)
        ->and(PersistenceItem::findOrFail($item->id)->title)->toBe('Stored');
});

test('insertAll creates many rows atomically', function () {
    PersistenceItem::insertAll([
        ['sku' => 'one', 'title' => 'One', 'quantity' => 1],
        ['sku' => 'two', 'title' => 'Two', 'quantity' => 2],
        ['sku' => 'three', 'title' => 'Three', 'quantity' => 3],
    ]);

    expect(PersistenceItem::all()->count())->toBe(3)
        ->and(PersistenceItem::orderBy('sku')->pluck('sku'))->toBe(['one', 'three', 'two']);
});

test('save on an unchanged persisted record is a no-op', function () {
    $item = PersistenceItem::insert(['sku' => 'a']);
    $affected = $item->save();

    expect($affected->affectedRows())->toBe(0)
        ->and(PersistenceItem::all()->count())->toBe(1);
});

test('save persists only the dirty fields of a single row', function () {
    $item = PersistenceItem::insert(['sku' => 'a', 'title' => 'Old', 'quantity' => 1]);

    $item->title = 'New';
    $item->save();

    expect($item->isDirty())->toBeFalse()
        ->and($item->wasChanged('title'))->toBeTrue()
        ->and($item->wasChanged('quantity'))->toBeFalse()
        ->and(PersistenceItem::findOrFail($item->id)->title)->toBe('New');
});

test('save rejects more than one dirty row in a single call', function () {
    PersistenceItem::insertAll([
        ['sku' => 'one', 'quantity' => 1],
        ['sku' => 'two', 'quantity' => 2],
    ]);

    $items = PersistenceItem::orderBy('id')->load();
    $items[0]->quantity = 10;
    $items[1]->quantity = 20;

    expect(fn () => $items->save())->toThrow(\Elveneek\Exception\AmbiguousWriteException::class);
});

test('saveCurrent persists only the iterator cursor row', function () {
    PersistenceItem::insertAll([
        ['sku' => 'one', 'quantity' => 1],
        ['sku' => 'two', 'quantity' => 2],
    ]);

    $items = PersistenceItem::orderBy('id')->load();
    $items[0]->quantity = 11;
    $items[1]->quantity = 22;
    $items->saveCurrent();

    PersistenceItem::flushIdentityCache();
    expect(PersistenceItem::where('sku', 'one')->value('quantity'))->toBe(11)
        ->and(PersistenceItem::where('sku', 'two')->value('quantity'))->toBe(2);
});

test('saveAll persists every dirty row in one transaction', function () {
    PersistenceItem::insertAll([
        ['sku' => 'one', 'quantity' => 1],
        ['sku' => 'two', 'quantity' => 2],
        ['sku' => 'three', 'quantity' => 3],
    ]);

    $items = PersistenceItem::orderBy('id')->load();
    foreach ($items as $item) {
        $item->quantity = $item->quantity * 10;
    }
    $items->saveAll();

    expect($items->affectedRows())->toBe(3);
    PersistenceItem::flushIdentityCache();
    expect(PersistenceItem::orderBy('sku')->pluck('quantity'))->toBe([10, 30, 20]);
});

test('original exposes the persisted snapshot and dirty lists differences', function () {
    $item = PersistenceItem::insert(['sku' => 'a', 'title' => 'Snapshot']);
    $item->title = 'Edited';

    expect($item->original('title'))->toBe('Snapshot')
        ->and($item->isDirty('title'))->toBeTrue()
        ->and($item->dirtyAttributes())->toBe(['title' => 'Edited']);
});

test('discardChanges reverts pending edits in memory', function () {
    $item = PersistenceItem::insert(['sku' => 'a', 'title' => 'Keep']);
    $item->title = 'Thrown away';
    $item->discardChanges();

    expect($item->title)->toBe('Keep')
        ->and($item->isDirty())->toBeFalse();
});

test('refresh reloads the row from the database when clean', function () {
    $item = PersistenceItem::insert(['sku' => 'a', 'title' => 'First']);
    \Elveneek\DB::connection()->exec("UPDATE persistence_items SET title = 'Behind the scenes' WHERE id = {$item->id}");

    $item->refresh();

    expect($item->title)->toBe('Behind the scenes');
});

test('refresh refuses a dirty record without force', function () {
    $item = PersistenceItem::insert(['sku' => 'a', 'title' => 'First']);
    $item->title = 'Local';

    expect(fn () => $item->refresh())->toThrow(\LogicException::class);
});

test('refresh with force reloads a dirty record from the database', function () {
    $item = PersistenceItem::insert(['sku' => 'a', 'title' => 'Local change']);
    \Elveneek\DB::connection()->exec("UPDATE persistence_items SET title = 'Committed behind' WHERE id = {$item->id}");

    $item->title = 'Still dirty';
    expect($item->isDirty())->toBeTrue();

    $item->refresh(true);

    expect($item->title)->toBe('Committed behind')
        ->and($item->isDirty())->toBeFalse();
});

test('reload is an alias of refresh', function () {
    $item = PersistenceItem::insert(['sku' => 'a', 'title' => 'Original']);
    \Elveneek\DB::connection()->exec("UPDATE persistence_items SET title = 'Reloaded' WHERE id = {$item->id}");

    $item->reload(force: true);

    expect($item->title)->toBe('Reloaded');
});

test('refresh throws when the record has no primary key', function () {
    $item = PersistenceItem::create(['sku' => 'nokey']);

    expect(fn () => $item->refresh())->toThrow(\RuntimeException::class);
});

test('fill only accepts whitelisted attributes', function () {
    $item = PersistenceItem::create(['sku' => 'a']);

    $item->fill(['title' => 'ok', 'quantity' => 5, 'sku' => 'blocked']);

    expect($item->title)->toBe('ok')
        ->and($item->quantity)->toBe(5)
        ->and($item->sku)->toBe('a');
});

test('forceFill bypasses the fillable allowlist', function () {
    $item = PersistenceItem::create(['sku' => 'a']);
    $item->forceFill(['sku' => 'overridden', 'title' => 'forced']);
    expect($item->sku)->toBe('overridden');
});

test('updateAll performs a parameterised bulk update and returns affected count', function () {
    PersistenceItem::insertAll([
        ['sku' => 'one', 'quantity' => 1],
        ['sku' => 'two', 'quantity' => 2],
    ]);

    $affected = PersistenceItem::where('quantity', '>=', 1)->updateAll(['note' => 'bulk']);

    expect($affected)->toBe(2)
        ->and(PersistenceItem::where('note', 'bulk')->count())->toBe(2);
});

test('updateAll with an empty attribute set is a no-op', function () {
    PersistenceItem::insert(['sku' => 'a']);
    expect(PersistenceItem::all()->updateAll([]))->toBe(0);
});

test('increment and decrement mutate the column atomically', function () {
    $item = PersistenceItem::insert(['sku' => 'a', 'quantity' => 5]);

    PersistenceItem::where('id', $item->id)->increment('quantity', 3);
    PersistenceItem::where('id', $item->id)->decrement('quantity', 2);

    PersistenceItem::flushIdentityCache();
    expect(PersistenceItem::findOrFail($item->id)->quantity)->toBe(6);
});

test('increment accepts a custom amount via raw expression', function () {
    $item = PersistenceItem::insert(['sku' => 'a', 'quantity' => 10]);

    PersistenceItem::where('id', $item->id)->increment('quantity', 7);

    PersistenceItem::flushIdentityCache();
    expect(PersistenceItem::findOrFail($item->id)->quantity)->toBe(17);
});

test('delete removes a single bound row', function () {
    $item = PersistenceItem::insert(['sku' => 'a']);
    $item->delete();

    expect($item->affectedRows())->toBe(1)
        ->and(PersistenceItem::findOrNull($item->id))->toBeNull();
});

test('deleteAll removes a filtered set and returns the count', function () {
    PersistenceItem::insertAll([
        ['sku' => 'one', 'quantity' => 1],
        ['sku' => 'two', 'quantity' => 2],
        ['sku' => 'three', 'quantity' => 3],
    ]);

    $removed = PersistenceItem::where('quantity', '>=', 2)->deleteAll();

    expect($removed)->toBe(2)
        ->and(PersistenceItem::all()->count())->toBe(1);
});

test('firstOrCreate is idempotent for the same criteria', function () {
    $first = PersistenceItem::firstOrCreate(['sku' => 'one'], ['title' => 'Once']);
    $again = PersistenceItem::firstOrCreate(['sku' => 'one'], ['title' => 'Twice']);

    expect($again->id)->toBe($first->id)
        ->and($again->title)->toBe('Once')
        ->and(PersistenceItem::all()->count())->toBe(1);
});

test('updateOrCreate creates then updates the matching row', function () {
    PersistenceItem::updateOrCreate(['sku' => 'one'], ['title' => 'Created', 'quantity' => 1]);
    PersistenceItem::updateOrCreate(['sku' => 'one'], ['title' => 'Updated', 'quantity' => 2]);

    PersistenceItem::flushIdentityCache();
    $item = PersistenceItem::where('sku', 'one')->firstOrFail();

    expect($item->title)->toBe('Updated')
        ->and($item->quantity)->toBe(2)
        ->and(PersistenceItem::all()->count())->toBe(1);
});

test('upsert inserts new rows and updates conflicts in one statement', function () {
    PersistenceItem::upsert([
        ['sku' => 'one', 'title' => 'One', 'quantity' => 1],
        ['sku' => 'two', 'title' => 'Two', 'quantity' => 2],
    ], uniqueBy: ['sku'], update: ['title', 'quantity']);

    PersistenceItem::upsert([
        ['sku' => 'one', 'title' => 'One!', 'quantity' => 11],
        ['sku' => 'three', 'title' => 'Three', 'quantity' => 3],
    ], uniqueBy: ['sku'], update: ['title', 'quantity']);

    PersistenceItem::flushIdentityCache();
    expect(PersistenceItem::orderBy('sku')->pluck('title'))->toBe(['One!', 'Three', 'Two'])
        ->and(PersistenceItem::where('sku', 'one')->value('quantity'))->toBe(11);
});

test('upsert refuses inconsistent column sets', function () {
    expect(fn () => PersistenceItem::upsert([
        ['sku' => 'one', 'title' => 'One', 'quantity' => 1],
        ['sku' => 'two', 'quantity' => 2],
    ], uniqueBy: ['sku'], update: ['title', 'quantity']))->toThrow(InvalidArgumentException::class);
});

test('ioi returns the primary key after insert', function () {
    $item = PersistenceItem::create(['sku' => 'a']);
    $item->save();

    expect($item->ioi())->toBe($item->id);
});

test('created_at and updated_at are stamped automatically on insert', function () {
    $item = PersistenceItem::insert(['sku' => 'a']);
    expect($item->created_at)->not->toBeNull()
        ->and($item->updated_at)->not->toBeNull();
});

test('updated_at advances on every update', function () {
    $item = PersistenceItem::insert(['sku' => 'a', 'title' => 'v1']);
    $firstUpdate = $item->updated_at;
    sleep(1);
    $item->title = 'v2';
    $item->save();

    expect($item->updated_at)->not->toBe($firstUpdate);
});

test('optimistic locking increments the version column and rejects stale writes', function () {
    $id = PersistenceItem::insert(['sku' => 'a', 'title' => 'v1'])->id;
    PersistenceItem::flushIdentityCache();
    $item = PersistenceItem::findOrFail($id);

    \Elveneek\DB::connection()->exec('UPDATE persistence_items SET lock_version = 5 WHERE id = ' . $id);

    $item->title = 'stale';
    expect(fn () => $item->save())->toThrow(\Elveneek\Exception\StaleModelException::class);
});

test('a successful save increments the version column', function () {
    $id = PersistenceItem::insert(['sku' => 'a', 'title' => 'v1'])->id;
    PersistenceItem::flushIdentityCache();
    $item = PersistenceItem::findOrFail($id);

    expect($item->lock_version)->toBe(0);
    $item->title = 'v2';
    $item->save();

    expect($item->lock_version)->toBe(1);
});

test('saveAll rolls back all rows when one fails and restores dirty state', function () {
    \Elveneek\ActiveRecord::$db->exec("INSERT INTO persistence_items (sku, title, quantity) VALUES ('taken', 'X', 0)");

    $batch = PersistenceItem::create()
        ->addRow(['sku' => 'first', 'quantity' => 1])
        ->addRow(['sku' => 'taken', 'quantity' => 2]);

    expect(fn () => $batch->saveAll())->toThrow(\Elveneek\Exception\QueryException::class);

    expect(PersistenceItem::where('sku', 'first')->withoutCache()->count())->toBe(0)
        ->and($batch->lastSaveErrors())->not->toBeEmpty();
});
