<?php

use Elveneek\Metadata\Inflector;

test('plural regular nouns append s', function (string $singular, string $plural) {
    expect(Inflector::plural($singular))->toBe($plural);
})->with([
    'product' => ['product', 'products'],
    'order' => ['order', 'orders'],
    'category' => ['category', 'categories'],
    'brand' => ['brand', 'brands'],
    'field' => ['field', 'fields'],
]);

test('singular regular nouns drop trailing s', function (string $plural, string $singular) {
    expect(Inflector::singular($plural))->toBe($singular);
})->with([
    'products' => ['products', 'product'],
    'brands' => ['brands', 'brand'],
    'orders' => ['orders', 'order'],
]);

test('consonant y swaps to ies and back', function () {
    expect(Inflector::plural('entity'))->toBe('entities')
        ->and(Inflector::singular('entities'))->toBe('entity')
        ->and(Inflector::plural('country'))->toBe('countries')
        ->and(Inflector::singular('countries'))->toBe('country');
});

test('vowel y stays and just appends s', function () {
    expect(Inflector::plural('boy'))->toBe('boys')
        ->and(Inflector::singular('boys'))->toBe('boy');
});

test('sibilant endings gain es', function () {
    expect(Inflector::plural('box'))->toBe('boxes')
        ->and(Inflector::plural('bus'))->toBe('buses')
        ->and(Inflector::plural('church'))->toBe('churches')
        ->and(Inflector::plural('dish'))->toBe('dishes')
        ->and(Inflector::plural('buzz'))->toBe('buzzes');
});

test('irregular words are handled by the lookup table', function () {
    expect(Inflector::plural('person'))->toBe('people')
        ->and(Inflector::singular('people'))->toBe('person')
        ->and(Inflector::plural('man'))->toBe('men')
        ->and(Inflector::singular('men'))->toBe('man')
        ->and(Inflector::plural('child'))->toBe('children')
        ->and(Inflector::plural('mouse'))->toBe('mice');
});

test('uncountable nouns stay the same in both directions', function () {
    expect(Inflector::plural('news'))->toBe('news')
        ->and(Inflector::singular('news'))->toBe('news')
        ->and(Inflector::plural('sheep'))->toBe('sheep')
        ->and(Inflector::singular('sheep'))->toBe('sheep')
        ->and(Inflector::plural('deer'))->toBe('deer');
});

test('addRule registers a custom mapping both ways', function () {
    Inflector::addRule('konkurs', 'konkursy');

    expect(Inflector::plural('konkurs'))->toBe('konkursy');

    Inflector::addRule('konkurs', 'konkurses');
    expect(Inflector::plural('konkurs'))->toBe('konkurses');
});

test('snake_case splits camelCase and pascalCase words', function () {
    expect(Inflector::snake('BlogPost'))->toBe('blog_post')
        ->and(Inflector::snake('blogPost'))->toBe('blog_post')
        ->and(Inflector::snake('HTMLParser'))->toBe('h_t_m_l_parser')
        ->and(Inflector::snake('already_lower'))->toBe('already_lower')
        ->and(Inflector::snake('id'))->toBe('id');
});

test('inflection is case insensitive and lowercases the result', function () {
    expect(Inflector::plural('PRODUCT'))->toBe('products')
        ->and(Inflector::singular('ORDERS'))->toBe('order');
});

test('compound underscored words inflect only the last segment', function () {
    expect(Inflector::plural('categories_to_product'))->toBe('categories_to_products')
        ->and(Inflector::singular('categories_to_products'))->toBe('categories_to_product')
        ->and(Inflector::plural('menu_item'))->toBe('menu_items');
});

test('plural of a singular ending in fe becomes ves', function () {
    expect(Inflector::plural('knife'))->toBe('knives')
        ->and(Inflector::plural('leaf'))->toBe('leaves');
});

test('singular handles es sibilant endings', function () {
    expect(Inflector::singular('boxes'))->toBe('box')
        ->and(Inflector::singular('churches'))->toBe('church')
        ->and(Inflector::plural('bus'))->toBe('buses');
});

test('a word ending in ss stays singular when no trailing s only', function () {
    expect(Inflector::singular('glass'))->toBe('glass')
        ->and(Inflector::plural('class'))->toBe('classes');
});

test('idempotency plural then singular returns the original for regular nouns', function () {
    expect(Inflector::singular(Inflector::plural('invoice')))->toBe('invoice');
});
