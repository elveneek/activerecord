<?php

use Elveneek\Records\RecordState;

function makeState(string $status = 'persisted', array $attributes = []): RecordState
{
    return new RecordState('App\\Product', 'products', 'id', $status, $attributes);
}

test('a fresh persisted state seeds attributes original and loaded columns', function () {
    $state = makeState('persisted', ['id' => 5, 'title' => 'Hello']);

    expect($state->attributes)->toBe(['id' => 5, 'title' => 'Hello'])
        ->and($state->original)->toBe($state->attributes)
        ->and($state->dirty)->toBe([])
        ->and($state->loadedColumns)->toHaveKeys(['id', 'title'])
        ->and($state->key())->toBe(5);
});

test('key returns null when the primary key is absent', function () {
    expect(makeState('new', ['title' => 'x'])->key())->toBeNull();
});

test('setting a changed value marks it dirty', function () {
    $state = makeState('persisted', ['title' => 'Old']);
    $state->set('title', 'New');

    expect($state->isDirty())->toBeTrue()
        ->and($state->isDirty('title'))->toBeTrue()
        ->and($state->dirty['title'])->toBe('New')
        ->and($state->attributes['title'])->toBe('New');
});

test('setting a value back to its original clears the dirty flag', function () {
    $state = makeState('persisted', ['title' => 'Old']);
    $state->set('title', 'New');
    $state->set('title', 'Old');

    expect($state->isDirty())->toBeFalse()
        ->and($state->dirty)->toBe([]);
});

test('a brand new record is always dirty for any attribute set', function () {
    $state = makeState('new', []);
    $state->set('title', 'Fresh');

    expect($state->isDirty('title'))->toBeTrue();
});

test('merge updates untouched fields without creating dirty entries', function () {
    $state = makeState('persisted', ['id' => 1, 'title' => 'Original']);
    $state->set('title', 'Dirty local');
    $state->merge(['brand_id' => 9, 'id' => 1], ['brand_id']);

    expect($state->attributes['brand_id'])->toBe(9)
        ->and($state->original['brand_id'])->toBe(9)
        ->and($state->isDirty('brand_id'))->toBeFalse()
        ->and($state->isDirty('title'))->toBeTrue()
        ->and($state->loadedColumns)->toHaveKey('brand_id');
});

test('merge does not overwrite a locally dirtied field', function () {
    $state = makeState('persisted', ['title' => 'Original']);
    $state->set('title', 'Local change');
    $state->merge(['title' => 'Incoming'], ['title']);

    expect($state->attributes['title'])->toBe('Local change')
        ->and($state->isDirty('title'))->toBeTrue();
});

test('markSaved snapshots attributes as original and clears dirty', function () {
    $state = makeState('new');
    $state->set('title', 'Created');
    $state->set('extra', 'value');
    $state->markSaved(['id' => 7]);

    expect($state->status)->toBe('persisted')
        ->and($state->attributes['id'])->toBe(7)
        ->and($state->dirty)->toBe([])
        ->and($state->original)->toBe($state->attributes)
        ->and($state->wasChanged)->toHaveKey('title')
        ->and($state->wasChanged)->toHaveKey('extra');
});

test('discardChanges reverts attributes and clears dirty and wasChanged', function () {
    $state = makeState('persisted', ['title' => 'Original']);
    $state->set('title', 'Changed');
    $state->discardChanges();

    expect($state->attributes['title'])->toBe('Original')
        ->and($state->dirty)->toBe([])
        ->and($state->wasChanged)->toBe([]);
});

test('setting a field on a deleted state throws', function () {
    $state = makeState('persisted', ['id' => 1]);
    $state->status = 'deleted';

    expect(fn () => $state->set('title', 'ghost'))
        ->toThrow(\LogicException::class);
});

test('isDirty with an unknown field returns false', function () {
    $state = makeState('persisted', ['title' => 'x']);
    $state->set('title', 'y');

    expect($state->isDirty('missing'))->toBeFalse();
});

test('placeholder flag is cleared as soon as a value is set', function () {
    $state = makeState('new');
    $state->placeholder = true;

    expect($state->placeholder)->toBeTrue();
    $state->set('title', 'real');
    expect($state->placeholder)->toBeFalse();
});

test('loaded columns default to attribute keys when none supplied', function () {
    $state = new RecordState('App\\X', 'xs', 'id', 'persisted', ['a' => 1, 'b' => 2], []);

    expect($state->loadedColumns)->toHaveKeys(['a', 'b']);
});

test('explicit loaded columns override attribute keys', function () {
    $state = new RecordState('App\\X', 'xs', 'id', 'persisted', ['a' => 1], ['a']);

    expect($state->loadedColumns)->toHaveKey('a');
});
