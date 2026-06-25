# Быстрый Старт

## Подключение

Минимальный вариант - использовать переменные окружения и стандартный `connect()`:

```php
use Elveneek\ActiveRecord;
use Elveneek\DB;

ActiveRecord::$db = ActiveRecord::connect();

// или так, если хочется пользоваться DB::connection()
DB::setConnection(ActiveRecord::connect());
```

`connect()` читает `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`. Если `DB_AUTO_RECONNECT=1`, будет создан `PDOProxy`, который умеет пересоздать соединение при типичной ошибке MySQL "server has gone away" для безопасных операций чтения.

Если `PDO` уже создан снаружи:

```php
DB::setConnection($pdo);

$pdo = DB::connection();
```

## Первая модель

```php
use Elveneek\ActiveRecord;

class Product extends ActiveRecord {}
```

Этого достаточно, чтобы работать с таблицей `products`.

```php
$product = Product::find(1);

echo $product->title;
echo $product->price;
```

По умолчанию имя таблицы строится из короткого имени класса: `Product` -> `products`, `Category` -> `categories`, `Person` -> `people`. Пространство имен не попадает в имя таблицы.

## Первая выборка

```php
$products = Product::where('is_active', true)
    ->where('price', '>=', 1000)
    ->orderBy('sort')
    ->limit(20);

foreach ($products as $product) {
    echo $product->title;
}
```

Методы `where()`, `orderBy()`, `limit()` только строят запрос. SQL выполняется, когда результат действительно нужен: при чтении свойства, `foreach`, доступе по индексу, `first()`, `count()`, `pluck()`, `toArray()` и других терминальных действиях.

## Что возвращается

Большинство методов построения запроса возвращают тот же тип модели (`static`), поэтому их можно продолжать цепочкой:

```php
$query = Product::where('category_id', 5)
    ->whereLike('title', '%phone%')
    ->orderBy('title');
```

Терминальные методы возвращают уже данные:

| Метод | Что возвращает | Когда идет SQL |
| --- | --- | --- |
| `Product::all()` | ленивый `Product`-набор | не сразу |
| `Product::find($id)` | ленивый `Product`-запрос по id | не сразу |
| `findOrNull($id)` | `Product|null` | сразу |
| `findOrFail($id)` | `Product` или `ModelNotFoundException` | сразу |
| `first()` | `Product|null` | сразу |
| `firstOrFail()` | `Product` или исключение | сразу |
| `count()` | `int` | сразу, если коллекция еще не загружена |
| `pluck($field)` | `array` | загружает строки |
| `value($field)` | значение поля или `null` | загружает первую строку |
| `toArray()` | массив строк или массив одной строки | загружает строки |
| `toJson()` | JSON-строку | загружает строки |
| `load()` | тот же `Product`-набор | принудительно загружает все строки |

`find()` специально ленивый, чтобы его можно было продолжать:

```php
$product = Product::select('id', 'title')->find(15);
$product = Product::where('site_id', 2)->find(15);
```

Если нужна немедленная проверка существования, используйте `findOrNull()` или `findOrFail()`.

## Запрос, коллекция и строка в одном объекте

Один объект ActiveRecord может вести себя как:

- ленивый запрос: `Product::where('price', '>', 1000)`;
- коллекция: `foreach (Product::all() as $product)`;
- конкретная строка: `$products[0]` или `$products->first()`;
- изменяемая модель: `$product->title = 'New'; $product->save();`.

Это не случайность, а основной дизайн. Пока объект не привязан к строке, он представляет набор. Когда строка получена через `foreach`, индекс или `first()`, возвращается row-bound объект: он связан с конкретной строкой и безопасен для изменения.

```php
$products = Product::where('id', '<=', 3)->orderBy('id');

foreach ($products as $product) {
    $product->title = 'Product #' . $product->id;
}

$products->saveAll();
```

## Ленивые и не ленивые операции

Ленивые:

- `all()`;
- `find()`;
- `findMany()`;
- `where()`, `orWhere()`, `whereIn()`, `select()`, `join()`, `orderBy()`, `limit()`, `with()`;
- пользовательские методы модели, если они только продолжают запрос.

Выполняют SQL:

- чтение свойства: `$product->title`;
- `first()`, `firstOrFail()`, `last()`;
- `foreach`, `$products[0]`, `count($products)`;
- `count()`, `exists()`, `pluck()`, `value()`, агрегаты;
- `toArray()`, `toJson()`, `json_encode($model)`;
- `load()`;
- `save()`, `saveAll()`, `updateAll()`, `delete()`, `deleteAll()`.

`remember()` и `with()` тоже принудительно догружают весь результат при первом выполнении, потому что для кэша и eager loading нужно сохранить полный набор строк.

## Типичная таблица

Библиотека не требует жесткой миграции, но стандартная таблица обычно выглядит так:

```sql
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title TEXT NULL,
    sort INT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL
);
```

`id` нужен для `find()`, identity map, сохранения существующих строк и связей. `sort`, `created_at`, `updated_at` необязательны, но если они есть, ActiveRecord умеет с ними помочь: `sort` при вставке становится равен `id`, `created_at` и `updated_at` заполняются автоматически.

## Прямо сейчас полезные примеры

```php
// Одна строка
$product = Product::findOrFail(1);
echo $product->price;

// Список
$products = Product::where('is_active', true)->orderBy('sort');

// Значения
$titles = Product::where('category_id', 5)->pluck('title');

// Сохранение
$product->price = 1500;
$product->save();

// Новая запись
$created = Product::create([
    'title' => 'New product',
    'price' => 1500,
])->save();

// Связь по products.category_id
echo Product::find(1)->category->title;

// Обратная связь по тому же category_id
$products = Category::find(1)->products;
```
