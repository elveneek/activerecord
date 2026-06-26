<?php

class ResolverProduct extends \Elveneek\ActiveRecord
{
    protected static string $table = 'products';
}

beforeEach(function () {
    if (!isset($_ENV['DB_HOST'])) {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    }

    \Elveneek\DB::clearConnectionResolver();
    $pdo = \Elveneek\ActiveRecord::connect();
    \Elveneek\DB::setConnection($pdo);
    $pdo->exec(file_get_contents(__DIR__ . '/data/mysql.sql'));

    \Elveneek\ActiveRecord::flushIdentityCache();
    \Elveneek\ActiveRecord::flushSchemaCache();
    \Elveneek\DB::flushQueryLog();
});

afterEach(function () {
    \Elveneek\DB::clearConnectionResolver();
});

test('connection resolver follows external pdo replacement and avoids stale identity cache', function () {
    $holder = new class (\Elveneek\DB::connection()) {
        public function __construct(public \PDO $db) {}
    };

    \Elveneek\DB::setConnectionResolver(static fn () => $holder->db);

    $cached = ResolverProduct::findOrFail(1);
    expect($cached->title)->toBe('First product');

    $replacement = \Elveneek\ActiveRecord::connect();
    $statement = $replacement->prepare('UPDATE products SET title = ? WHERE id = ?');
    $statement->execute(['Resolver product', 1]);
    $holder->db = $replacement;

    $fresh = ResolverProduct::findOrFail(1);

    expect($fresh->title)->toBe('Resolver product')
        ->and(\Elveneek\DB::connection())->toBe($replacement)
        ->and(\Elveneek\ActiveRecord::$db)->toBe($replacement);
});