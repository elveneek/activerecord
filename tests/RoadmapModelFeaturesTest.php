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

class RoadmapProtectedProduct extends \Elveneek\ActiveRecord
{
    protected static string $table = 'products';
    protected static array $fillable = ['title', 'type'];
    protected static array $hidden = ['text'];
    protected static array $visible = [];
    protected static array $appends = ['display_title'];

    protected function getDisplayTitle(): string
    {
        return strtoupper((string) $this->getRaw('title'));
    }
}

class RoadmapVisibleProduct extends \Elveneek\ActiveRecord
{
    protected static string $table = 'products';
    protected static array $hidden = ['id'];
    protected static array $visible = ['id', 'display_title'];
    protected static array $appends = ['display_title', 'internal_label'];

    protected function getDisplayTitle(): string
    {
        return strtoupper((string) $this->getRaw('title'));
    }

    protected function getInternalLabel(): string
    {
        return 'internal-' . $this->getRaw('id');
    }
}
class RoadmapScopedProduct extends \Elveneek\ActiveRecord
{
    protected static string $table = 'products';

    public static function published(): static
    {
        return static::where('type', 'published');
    }

    public function deleted(): static
    {
        return $this->where('type', 'deleted');
    }

    protected function expensive(int $from): static
    {
        return $this->where('sort', '>=', $from);
    }
}

class RoadmapBaseUser extends \Elveneek\ActiveRecord
{
    protected static string $table = 'roadmap_users';
}

class RoadmapModerator extends RoadmapBaseUser
{
    protected static string $table = 'roadmap_moderators';
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

test('conventional casts keep ids integer and is fields boolean', function () {
    \Elveneek\ActiveRecord::$db->exec(
        'ALTER TABLE products ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 0'
    );
    \Elveneek\ActiveRecord::$db->exec(
        "UPDATE products SET is_active = 1, created_at = '2026-06-24 12:00:00' WHERE id = 1"
    );
    \Elveneek\ActiveRecord::flushSchemaCache();
    \Elveneek\ActiveRecord::flushIdentityCache();

    $active = RoadmapProduct::findOrFail(1);
    $inactive = RoadmapProduct::findOrFail(2);

    expect($active->id)->toBeInt()->toBe(1)
        ->and($active->is_active)->toBeBool()->toBeTrue()
        ->and($inactive->is_active)->toBeBool()->toBeFalse()
        ->and($active->created_at)->toBeString();
});

test('primary key is integer both after select and immediately after insert', function () {
    $selected = RoadmapProduct::findOrFail(1);
    $inserted = RoadmapProduct::insert(['title' => 'Inserted id cast test']);

    expect($selected->id)->toBeInt()->toBe(1)
        ->and($inserted->id)->toBeInt()->toBe(6)
        ->and($inserted->insert_id)->toBeInt()->toBe(6);
});

test('subclassed models can use their own table without single table inheritance type magic', function () {
    \Elveneek\ActiveRecord::$db->exec('DROP TABLE IF EXISTS roadmap_users');
    \Elveneek\ActiveRecord::$db->exec('DROP TABLE IF EXISTS roadmap_moderators');
    \Elveneek\ActiveRecord::$db->exec(
        'CREATE TABLE roadmap_users ('
        . 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
        . 'name VARCHAR(255) NULL, '
        . 'type VARCHAR(255) NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8'
    );
    \Elveneek\ActiveRecord::$db->exec(
        'CREATE TABLE roadmap_moderators ('
        . 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
        . 'name VARCHAR(255) NULL, '
        . 'type VARCHAR(255) NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8'
    );
    \Elveneek\ActiveRecord::flushSchemaCache();
    \Elveneek\ActiveRecord::flushIdentityCache();

    $moderator = RoadmapModerator::create(['name' => 'Ada'])->save();

    $users = (int) \Elveneek\ActiveRecord::$db->query('SELECT COUNT(*) FROM roadmap_users')->fetchColumn();
    $storedType = \Elveneek\ActiveRecord::$db->query('SELECT type FROM roadmap_moderators WHERE id = 1')->fetchColumn();

    expect($moderator)->toBeInstanceOf(RoadmapModerator::class)
        ->and($moderator->table)->toBe('roadmap_moderators')
        ->and($moderator->type)->toBeNull()
        ->and($users)->toBe(0)
        ->and($storedType)->toBeNull();
});

test('fillable protects fill while explicit only and forceFill can override it', function () {
    $product = RoadmapProtectedProduct::findOrFail(1);

    $product->fill([
        'title' => 'Allowed title',
        'type' => 'allowed-type',
        'text' => 'blocked text',
    ]);

    expect($product->title)->toBe('Allowed title')
        ->and($product->type)->toBe('allowed-type')
        ->and($product->text)->toBeNull();

    $product->fill(['text' => 'allowed by call'], only: ['text']);
    expect($product->text)->toBe('allowed by call');

    $product->forceFill(['text' => 'trusted value']);
    expect($product->text)->toBe('trusted value');

    expect(fn () => RoadmapProduct::findOrFail(1)->fill(['title' => 'unsafe']))
        ->toThrow(\Elveneek\Exception\MassAssignmentException::class);
});

test('hidden fields stay accessible but are omitted and appends serialize accessors', function () {
    $product = RoadmapProtectedProduct::findOrFail(1);
    $product->forceFill(['text' => 'secret']);

    $array = $product->toArray();
    $json = json_decode($product->toJson(), true, 512, JSON_THROW_ON_ERROR);

    expect($product->text)->toBe('secret')
        ->and($product->display_title)->toBe('FIRST PRODUCT')
        ->and($array)->not->toHaveKey('text')
        ->and($array)->toHaveKey('display_title', 'FIRST PRODUCT')
        ->and($json)->not->toHaveKey('text')
        ->and($json)->toHaveKey('display_title', 'FIRST PRODUCT');
});

test('visible is a strict serialization allowlist including appended fields', function () {
    $array = RoadmapVisibleProduct::findOrFail(1)->toArray();

    expect($array)->toBe([
        'id' => 1,
        'display_title' => 'FIRST PRODUCT',
    ]);
});
test('model methods act as scopes without a naming convention', function () {
    $published = RoadmapScopedProduct::published();
    $deleted = RoadmapScopedProduct::where('category_id', 1)->deleted();
    $expensive = RoadmapScopedProduct::where('category_id', 1)->expensive(1000);

    expect($published->bindings())->toBe(['published'])
        ->and($deleted->bindings())->toBe([1, 'deleted'])
        ->and($expensive->bindings())->toBe([1, 1000])
        ->and($expensive->toSql())->toContain('`category_id` = ?')
        ->and($expensive->toSql())->toContain('`sort` >= ?');
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

