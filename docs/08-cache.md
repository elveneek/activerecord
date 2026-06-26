# Кеш

В ActiveRecord несколько уровней кэша. Они решают разные задачи и включаются в разное время.

## Карта уровней

| Уровень | Где живет | Что экономит | Как включается |
| --- | --- | --- | --- |
| Локальная коллекция | В конкретном объекте запроса | Повторное чтение уже загруженных строк | Автоматически после первого чтения |
| Identity map | Статически в `ActiveRecord` | Повторный `find()`/`findMany()` по primary key | Автоматически |
| Негативный кэш id | Identity map | Повторный поиск отсутствующего id | Автоматически после lookup |
| Relation cache | В `RecordState` строки | Повторное чтение связи у той же строки | Автоматически |
| Result cache | Статически в `ActiveRecord` | Повтор всего запроса | Явно через `remember()` |
| Schema cache | Статически в `ActiveRecord` | Повторное чтение списка колонок | Автоматически |

## Локальная коллекция

```php
$products = Product::where('category_id', 5);

$products[0];       // грузит первую строку
$products[0];       // берет из уже загруженной коллекции
$products->toArray(); // догружает все строки
```

Один объект запроса помнит свою `RecordCollection`. Пока вы работаете с тем же объектом, уже прочитанные строки не гидратируются заново.

## Identity map

Identity map хранит canonical `RecordState` по ключу:

```text
connection + model class + primary key
```

Пример:

```php
Product::flushIdentityCache();
DB::flushQueryLog();

Product::find(1)->title; // SQL
Product::find(1)->title; // без SQL, identity map
```

Если строка была загружена полностью, повторный `find(1)` переиспользует ее состояние.

Частичная строка помнит выбранные колонки:

```php
Product::select('id', 'title')->find(1)->title; // SQL: id,title
Product::find(1)->brand_id;                     // SQL: догрузить недостающие колонки
```

`findMany()` спрашивает базу только про id, которых нет в identity map:

```php
Product::find(1)->title;
Product::find(2)->title;

Product::findMany([1, 2, 3, 4])->pluck('id');
// SQL только для 3 и 4
```

`findMany()` сохраняет порядок id из входного массива.

## Негативный кэш

Если lookup по primary key не нашел строку, этот факт тоже запоминается:

```php
Product::find(999)->isEmpty(); // SQL
Product::find(999)->isEmpty(); // без повторного SQL
```

После вставок, update/delete и ручной инвалидации соответствующие записи сбрасываются.

## Relation cache

Связи кэшируются в состоянии строки:

```php
$product = Product::findOrFail(1);

$product->category->title; // SQL для связи
$product->category->title; // relation cache
```

При изменении foreign key relation cache этой связи очищается:

```php
$product->category = $newCategory;
```

После update строки `save()` очищает relation cache строки, потому что связанные поля могли поменяться.

## Result cache

Result cache включается явно:

```php
$products = Product::where('category_id', 5)
    ->orderBy('id')
    ->remember(60);

$first = $products->toArray();  // SQL
$second = Product::where('category_id', 5)
    ->orderBy('id')
    ->remember(60)
    ->toArray();                // из result cache
```

`remember($seconds, $key = null)` хранит полный результат запроса. Если ключ не задан, используется fingerprint из SQL, bindings и model class.

```php
$menu = Category::whereNull('category_id')
    ->remember(300, 'main-menu');

$forever = Category::all()
    ->rememberForever('all-categories');
```

Result cache возвращает fully loaded collection. Поэтому первый запрос с `remember()` загружает все строки сразу.

## Инвалидация result cache

Каждый кэшированный запрос хранит зависимости таблиц:

- основная таблица;
- join-таблицы;
- таблицы из subquery;
- таблицы из exists/in подзапросов.

Запись увеличивает generation затронутой таблицы:

- `save()` и `saveAll()`;
- `updateAll()`;
- `delete()` и `deleteAll()`;
- `truncate()`;
- pivot `attach()`, `detach()`, `sync()`;
- `invalidateTableCache($table)`.

Если generation таблицы изменился, старый result cache больше не используется.

```php
$before = Product::where('category_id', 1)->remember(60)->pluck('title');

Product::where('category_id', 1)->updateAll(['title' => 'Changed']);

$after = Product::where('category_id', 1)->remember(60)->pluck('title');
// свежий SQL, потому что таблица products инвалидирована
```

## Отключение кэша

```php
Product::find(1)->withoutCache()->first();
```

`withoutCache()` отключает и identity map, и result cache для текущего запроса.

```php
Product::where('id', 1)->withoutIdentityMap()->first();
```

`withoutIdentityMap()` отключает только identity map. Result cache, если был задан `remember()`, остается возможным.

Глобальные сбросы:

```php
Product::flushIdentityCache();
Product::flushSchemaCache();
Product::invalidateTableCache('products');
```

Низкоуровневые snapshot helpers используются транзакциями и тестами:

```php
$identity = Product::captureIdentitySnapshot();
Product::restoreIdentitySnapshot($identity);

$runtime = Product::captureRuntimeSnapshot();
Product::restoreRuntimeSnapshot($runtime);
```

Runtime snapshot включает identity map, result cache и generations таблиц. В обычном приложении обычно достаточно `DB::transaction()`, который вызывает эти методы сам.

## Диагностика cache hit

```php
$product = Product::find(1);

$product->title;

$product->cacheHit();
$product->cacheSource();
```

`cacheSource()` возвращает:

| Значение | Что значит |
| --- | --- |
| `database` | строка пришла из БД |
| `identity` | результат собран из identity map |
| `mixed` | часть id была в identity map, часть догружалась из БД |
| `shared` | результат пришел из result cache |

`cacheHit()` - `true`, если source не `database`.

## Query log показывает кэш

```php
DB::flushQueryLog();

Product::find(1)->title;
Product::find(1)->title;

$events = DB::queryLog();
```

SQL-события имеют `sql != null`. Cache hit записывается как событие с `sql = null` и `source = identity-map` или `query-cache`.

Можно подписаться на события:

```php
DB::listen(function ($event) {
    logger($event->source, [
        'sql' => $event->sql,
        'bindings' => $event->bindings,
        'duration' => $event->duration,
    ]);
});
```

## Транзакции и rollback

Перед транзакцией ActiveRecord делает snapshot runtime-кэшей:

- identity map;
- result cache;
- generations таблиц.

Если транзакция откатывается, snapshot восстанавливается:

```php
try {
    DB::transaction(function () {
        $product = Product::findOrFail(1);
        $product->title = 'Uncommitted';
        $product->save();

        Product::where('id', 1)->remember(60)->firstOrFail();

        throw new RuntimeException('rollback');
    });
} catch (RuntimeException) {
}

Product::where('id', 1)->remember(60)->firstOrFail()->title;
// не увидит откатившееся значение
```

Это защищает от утечек uncommitted данных через identity map или result cache.

## Schema cache

Список колонок таблицы читается через `SELECT * FROM table LIMIT 0` и кэшируется:

```php
$columns = Product::schemaColumns('products');
$columns = Product::schemaColumns('products', refresh: true);
```

После изменения схемы вызывайте:

```php
Product::flushSchemaCache();
```

`ModelMetadata` тоже сбрасывается этим методом, потому что casts и имя таблицы завязаны на metadata.
