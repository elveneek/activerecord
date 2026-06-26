<?php

use Elveneek\Cache\IdentityMap;
use Elveneek\Records\RecordState;

function mapState(int $id, array $attributes = []): RecordState
{
    $attributes = ['id' => $id] + $attributes;
    return new RecordState('App\\Product', 'products', 'id', 'persisted', $attributes);
}

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }
});

test('put stores a state and get retrieves it by id', function () {
    $map = new IdentityMap();
    $state = mapState(1, ['title' => 'A']);
    $map->put('default', $state);

    expect($map->get('default', 'App\\Product', 1))->toBe($state);
});

test('get returns null for an unknown id', function () {
    $map = new IdentityMap();

    expect($map->get('default', 'App\\Product', 99))->toBeNull();
});

test('put with a null key is a no-op returning the original state', function () {
    $map = new IdentityMap();
    $state = new RecordState('App\\Product', 'products', 'id', 'new', []);

    expect($map->put('default', $state))->toBe($state)
        ->and($map->snapshot()['states'])->toBe([]);
});

test('put is idempotent and keeps the first stored instance', function () {
    $map = new IdentityMap();
    $first = mapState(1, ['title' => 'First']);
    $second = mapState(1, ['title' => 'Second']);

    $map->put('default', $first);
    $returned = $map->put('default', $second);

    expect($returned)->toBe($first)
        ->and($map->get('default', 'App\\Product', 1))->toBe($first);
});

test('markMissing and isMissing track negative cache', function () {
    $map = new IdentityMap();
    $map->markMissing('default', 'App\\Product', 42);

    expect($map->isMissing('default', 'App\\Product', 42))->toBeTrue()
        ->and($map->isMissing('default', 'App\\Product', 43))->toBeFalse();
});

test('put clears the missing flag for the same id', function () {
    $map = new IdentityMap();
    $map->markMissing('default', 'App\\Product', 1);
    $map->put('default', mapState(1));

    expect($map->isMissing('default', 'App\\Product', 1))->toBeFalse();
});

test('invalidate removes a single state', function () {
    $map = new IdentityMap();
    $map->put('default', mapState(1));

    $map->invalidate('default', 'App\\Product', 1);

    expect($map->get('default', 'App\\Product', 1))->toBeNull();
});

test('invalidateTable removes every state for that table only', function () {
    $map = new IdentityMap();
    $product = new RecordState('App\\Product', 'products', 'id', 'persisted', ['id' => 1]);
    $category = new RecordState('App\\Category', 'categories', 'id', 'persisted', ['id' => 2]);
    $map->put('default', $product);
    $map->put('default', $category);

    $map->invalidateTable('default', 'products');

    expect($map->get('default', 'App\\Product', 1))->toBeNull()
        ->and($map->get('default', 'App\\Category', 2))->toBe($category);
});

test('invalidateTable is scoped to the connection', function () {
    $map = new IdentityMap();
    $state = mapState(1);
    $map->put('other', $state);

    $map->invalidateTable('default', 'products');

    expect($map->get('other', 'App\\Product', 1))->toBe($state);
});

test('snapshot captures and restore replays the recorded fields', function () {
    $map = new IdentityMap();
    $state = mapState(1, ['title' => 'Original']);
    $map->put('default', $state);
    $snapshot = $map->snapshot();

    $state->set('title', 'Mutated');
    $map->markMissing('default', 'App\\Product', 5);

    $map->restore($snapshot);

    expect($state->attributes['title'])->toBe('Original')
        ->and($state->dirty)->toBe([])
        ->and($map->isMissing('default', 'App\\Product', 5))->toBeFalse()
        ->and($map->get('default', 'App\\Product', 1))->toBe($state);
});

test('clear empties both states and missing entries', function () {
    $map = new IdentityMap();
    $map->put('default', mapState(1));
    $map->markMissing('default', 'App\\Product', 2);
    $map->clear();

    expect($map->get('default', 'App\\Product', 1))->toBeNull()
        ->and($map->isMissing('default', 'App\\Product', 2))->toBeFalse();
});

test('integer and string ids are stored under distinct keys', function () {
    $map = new IdentityMap();
    $int = mapState(1);
    $string = new RecordState('App\\Product', 'products', 'id', 'persisted', ['id' => '1']);

    $map->put('default', $int);
    $map->put('default', $string);

    expect($map->get('default', 'App\\Product', 1))->toBe($int)
        ->and($map->get('default', 'App\\Product', '1'))->toBe($string);
});
