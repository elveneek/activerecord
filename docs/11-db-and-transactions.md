# DB И Транзакции

`Elveneek\DB` - статический сервис для подключения, низкоуровневого выполнения SQL, query log, raw expressions, standalone query builder и транзакций.

ActiveRecord использует default connection из `DB`/`ActiveRecord::$db`.

## Подключение

Через переменные окружения:

```php
use Elveneek\ActiveRecord;
use Elveneek\DB;

DB::setConnection(ActiveRecord::connect());
```

Через готовый `PDO`:

```php
DB::setConnection($pdo);
```

Получить соединение:

```php
$pdo = DB::connection();
```

Можно хранить именованные подключения:

```php
DB::setConnection($reportingPdo, 'reporting');
$pdo = DB::connection('reporting');
```

Если `PDO` живет во внешнем контейнере и этот контейнер может заменить объект при reconnect, подключите resolver:

```php
DB::setConnectionResolver(fn () => doitClass::$instance->db);
```

`DB::connection()` будет вызывать resolver и возвращать актуальный `PDO`. Для default connection ActiveRecord также синхронизирует `ActiveRecord::$db`, identity map, result cache и schema cache с текущим объектом соединения.

Resolver можно использовать и для именованного подключения:

```php
DB::setConnectionResolver(fn () => $container->reportingPdo(), 'reporting');
```

`DB::setConnection($pdo)` отключает resolver для этого имени и снова фиксирует конкретный объект `PDO`. Если нужно только обновить объект без отключения resolver, используйте `DB::replaceConnection($pdo)`. Отключить resolver явно можно через `DB::clearConnectionResolver()`.

Текущий ActiveRecord API работает с default connection. Низкоуровневые `DB::execute()` и `DB::runQuery()` принимают имя connection.

## `ActiveRecord::connect()`

```php
$pdo = ActiveRecord::connect();
```

Метод читает:

- `DB_HOST`;
- `DB_NAME`;
- `DB_USER`;
- `DB_PASSWORD`;
- `DB_AUTO_RECONNECT`.

Настройки PDO:

- `ERRMODE_EXCEPTION`;
- `FETCH_OBJ`;
- `EMULATE_PREPARES = false`;
- `SET NAMES utf8`;
- `SET sql_mode = ''`.

Если `DB_AUTO_RECONNECT` не пустой, создается `PDOProxy`.

## PDOProxy

`PDOProxy` наследует `PDO` и перехватывает:

- `prepare()`;
- `query()`;
- `exec()`.

При MySQL ошибке `HY000/2006` (`server has gone away`) он пересоздает default connection через `ActiveRecord::connect()` и повторяет безопасные операции чтения. Записи с неизвестным результатом не повторяются автоматически.

## Query Builder

```php
$query = DB::table('products')
    ->where('category_id', 5)
    ->orderBy('id');

$rows = $query->rows();
```

`DB::table()` возвращает standalone `QueryBuilder`, не модель. Подробнее: [Query Builder](09-query-builder.md).

## Raw expressions

```php
DB::raw('views + ?', [1]);
DB::now();
```

`DB::now()` - короткая форма `DB::raw('CURRENT_TIMESTAMP')`.

Raw expressions можно использовать в сохранении и bulk update:

```php
Product::where('id', 1)->updateAll([
    'views' => DB::raw('views + ?', [1]),
    'updated_at' => DB::now(),
]);
```

## Низкоуровневое выполнение SQL

```php
$statement = DB::execute(
    'UPDATE products SET title = ? WHERE id = ?',
    ['New title', 1],
);
```

`execute()`:

- готовит statement;
- биндит значения с типами `NULL`, `BOOL`, `INT`, `STR`;
- выполняет запрос;
- пишет событие в query log;
- при ошибке бросает `QueryException`, где есть исходный SQL и bindings.

Выборка по builder:

```php
$rows = DB::runQuery(DB::table('products')->where('id', 1));
```

Обычно эти методы нужны ядру или инфраструктурному коду. В обычной модели проще использовать ActiveRecord API.

## Query log

```php
DB::enableQueryLog(true);
DB::flushQueryLog();

Product::find(1)->title;

$events = DB::queryLog();
```

Событие содержит:

- `sql`;
- `bindings`;
- `duration`;
- `connection`;
- `model`;
- `source`;
- `rows`.

Cache hit записывается как событие с `sql = null` и source вроде `identity-map` или `query-cache`.

Отключить логирование:

```php
DB::enableQueryLog(false);
```

## Listeners

```php
DB::listen(function ($event) {
    logger()->debug('SQL', [
        'sql' => $event->sql,
        'bindings' => $event->bindings,
        'source' => $event->source,
        'duration' => $event->duration,
    ]);
});
```

Listener вызывается для SQL и cache events.

## Транзакции

```php
DB::transaction(function () {
    $order = Order::create(['status' => 'new'])->save();

    foreach ($items as $item) {
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $item->product_id,
        ])->save();
    }
});
```

Если callback выбросит исключение, транзакция откатится, а исключение пойдет дальше.

Метод возвращает результат callback-а:

```php
$order = DB::transaction(function () {
    return Order::create(['status' => 'new'])->save();
});
```

## Вложенные транзакции

Вложенные `DB::transaction()` используют savepoints:

```php
DB::transaction(function () {
    DB::transaction(function () {
        // SAVEPOINT
    });
});
```

Если внутренний savepoint откатился, ActiveRecord восстанавливает runtime snapshot для кэшей и состояний, относящихся к этой попытке.

## Повтор при deadlock

```php
DB::transaction(function () {
    // работа
}, attempts: 3);
```

Повтор выполняется только на внешнем уровне транзакции и только для MySQL deadlock/lock wait ошибок с кодами `1205` и `1213`.

Перед повтором есть небольшая пауза, зависящая от номера попытки.

## `afterCommit()`

```php
DB::transaction(function () {
    $product = Product::findOrFail(1);
    $product->title = 'Changed';
    $product->save();

    DB::afterCommit(function () use ($product) {
        dispatch(new ReindexProduct($product->id));
    });
});
```

Если транзакции нет, callback выполняется сразу:

```php
DB::afterCommit(fn () => dispatch($job));
```

Если callback зарегистрирован внутри вложенной успешной транзакции, он выполнится один раз после commit внешней транзакции.

Если savepoint откатился, callbacks из него удаляются.

## Транзакции и in-memory состояния

`DB::transaction()` делает snapshot runtime-состояния ActiveRecord:

- identity map;
- result cache;
- generations таблиц.

При rollback snapshot восстанавливается. Это значит, что откатившаяся запись не останется в identity map или result cache как будто она была закоммичена.

```php
$product = Product::findOrFail(1);

try {
    DB::transaction(function () use ($product) {
        $product->title = 'Rolled back';
        $product->save();

        throw new RuntimeException('rollback');
    });
} catch (RuntimeException) {
}

echo $product->title; // исходное значение
```

## Блокировки строк

```php
DB::transaction(function () {
    $product = Product::where('id', 1)
        ->lockForUpdate()
        ->firstOrFail();

    $product->stock--;
    $product->save();
});
```

`lockForUpdate()` добавляет `FOR UPDATE`, `sharedLock()` добавляет `LOCK IN SHARE MODE`.

Используйте их внутри транзакции, иначе блокировка обычно теряет смысл.
