<?php

class ChunkItem extends \Elveneek\ActiveRecord
{
    protected static string $table = 'chunk_items';
    protected static array $casts = ['id' => 'int', 'position' => 'int'];
}

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }
    $pdo = \Elveneek\ActiveRecord::connect();
    \Elveneek\DB::setConnection($pdo);
    $pdo->exec('DROP TABLE IF EXISTS chunk_items');
    $pdo->exec(
        'CREATE TABLE chunk_items ('
        . 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
        . 'title VARCHAR(255) NULL, '
        . 'position INT NOT NULL DEFAULT 0'
        . ') ENGINE=InnoDB'
    );
    ChunkItem::insertAll(
        array_map(fn ($i) => ['title' => "Item {$i}", 'position' => $i], range(1, 25))
    );
    \Elveneek\ActiveRecord::flushIdentityCache();
    \Elveneek\ActiveRecord::flushSchemaCache();
});

test('paginate splits the result into fixed size pages', function () {
    $page = ChunkItem::orderBy('id')->paginate(10, 0);

    expect(iterator_count($page))->toBe(10)
        ->and($page[0]->position)->toBe(1)
        ->and($page[9]->position)->toBe(10)
        ->and($page->foundRows())->toBe(25);
});

test('paginate returns the partial last page', function () {
    $page = ChunkItem::orderBy('id')->paginate(10, 2);

    expect(iterator_count($page))->toBe(5)
        ->and($page->foundRows())->toBe(25)
        ->and($page[0]->position)->toBe(21);
});

test('paginate applies on top of a where clause', function () {
    $page = ChunkItem::where('position', '>', 20)->orderBy('id')->paginate(10, 0);

    expect(iterator_count($page))->toBe(5)
        ->and($page->foundRows())->toBe(5);
});

test('simplePaginate behaves like paginate', function () {
    $page = ChunkItem::orderBy('id')->simplePaginate(10, 1);

    expect(iterator_count($page))->toBe(10)
        ->and($page[0]->position)->toBe(11);
});

test('paginate rejects invalid page sizes', function () {
    expect(fn () => ChunkItem::all()->paginate(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ChunkItem::all()->paginate(10, -1))->toThrow(InvalidArgumentException::class);
});

test('chunkById visits every row exactly once in ascending id order', function () {
    $visited = [];

    ChunkItem::orderBy('position', 'desc')->chunkById(7, function ($chunk) use (&$visited) {
        foreach ($chunk as $item) {
            $visited[] = $item->id;
        }
    });

    sort($visited);
    expect($visited)->toBe(range(1, 25));
});

test('chunkById passes bounded chunks to the callback', function () {
    $sizes = [];

    ChunkItem::all()->chunkById(10, function ($chunk) use (&$sizes) {
        $sizes[] = count($chunk);
    });

    expect($sizes)->toBe([10, 10, 5]);
});

test('eachById iterates every row individually', function () {
    $count = 0;
    $firstId = null;

    ChunkItem::all()->eachById(100, function ($item) use (&$count, &$firstId) {
        if ($count === 0) {
            $firstId = $item->id;
        }
        $count++;
    });

    expect($count)->toBe(25)
        ->and($firstId)->toBe(1);
});

test('chunkById rejects a non positive chunk size', function () {
    expect(fn () => ChunkItem::all()->chunkById(0, fn () => null))->toThrow(InvalidArgumentException::class);
});

test('chunkById stops early when the set is smaller than a chunk', function () {
    $calls = 0;

    ChunkItem::where('id', '<=', 3)->chunkById(10, function ($chunk) use (&$calls) {
        $calls++;
    });

    expect($calls)->toBe(1);
});

test('lastPage reports the number of pages for the set', function () {
    $page = ChunkItem::orderBy('id')->paginate(10, 0);

    expect($page->lastPage())->toBe(3)
        ->and($page->hasNextPage())->toBeTrue();
});

test('foundRows ignores the active limit and offset', function () {
    $page = ChunkItem::orderBy('id')->limit(4)->offset(2);

    expect(iterator_count($page))->toBe(4)
        ->and($page->foundRows())->toBe(25);
});
