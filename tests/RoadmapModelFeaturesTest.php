<?php

class RoadmapProduct extends \Elveneek\ActiveRecord
{
    protected static string $table = 'products';
    protected static array $casts = ['id' => 'int', 'category_id' => 'int'];

    public function explicitCategory()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }
    \Elveneek\ActiveRecord::$db = \Elveneek\ActiveRecord::connect();
    \Elveneek\ActiveRecord::$db->exec(file_get_contents(__DIR__ . '/data/mysql.sql'));
    \Elveneek\ActiveRecord::flushIdentityCache();
    \Elveneek\ActiveRecord::flushSchemaCache();
    if (!class_exists('Category')) {
        class Category extends \Elveneek\ActiveRecord {}
    }
});

test('model table override casts and explicit relation work together', function () {
    $product = RoadmapProduct::findOrFail(1);

    expect($product->table)->toBe('products')
        ->and($product->id)->toBeInt()
        ->and($product->explicitCategory->title)->toBe('First category');
});

test('conditional fluency and strict partial attributes are deterministic', function () {
    $query = RoadmapProduct::all()
        ->when(1, fn ($query, $id) => $query->where('id', $id))
        ->unless(false, fn ($query) => $query->whereNotNull('title'))
        ->tap(fn ($query) => expect($query->toSql())->toContain('WHERE'));

    expect($query->firstOrFail()->id)->toBe(1);

    RoadmapProduct::strictMode(true);
    try {
        expect(fn () => RoadmapProduct::select('id')->findOne(1)->title)
            ->toThrow(\Elveneek\Exception\MissingAttributeException::class);
    } finally {
        RoadmapProduct::strictMode(false);
    }
});

