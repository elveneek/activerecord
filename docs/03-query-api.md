# Основной API Запросов

Этот раздел про нормальный API модели: `Product::where(...)->orderBy(...)->first()`. Низкоуровневый `QueryBuilder` описан отдельно в [Query Builder](09-query-builder.md).

## Точка входа

```php
Product::all();              // вся таблица, лениво
Product::find(1);            // поиск по primary key, лениво
Product::findMany([5, 2]);   // набор по primary key, порядок id сохраняется
Product::findOrNull(1);      // Product|null, выполняет запрос сразу
Product::findOrFail(1);      // Product или ModelNotFoundException
```

`findMany([])` возвращает пустой набор и не делает SQL-запрос.

`find()`, `findOrFail()`, `findOrNull()` учитывают уже построенную цепочку запроса. Поэтому `select()`/`where()` перед ними применяются к результату:

```php
Product::select('id', 'title')->findOrFail(1); // вернёт только id и title
Product::where('is_active', true)->find(1);    // найдёт id=1 только среди активных
```

## Простые условия

Безопасная форма:

```php
Product::where('title', 'Phone');
Product::where('price', '>=', 1000);
Product::where(['category_id' => 5, 'is_active' => true]);
```

`null` превращается в `IS NULL` или `IS NOT NULL`:

```php
Product::where('deleted_at', null);
Product::where('deleted_at', '!=', null);
```

Legacy/raw-форма тоже поддерживается:

```php
Product::where('price > ? AND stock > ?', 1000, 0);
Product::where('title LIKE ?', '%phone%');
Product::where('id IN (?)', [1, 2, 3]);
```

Raw-форма полезна для совместимости, но в новом коде лучше предпочитать структурные методы: они валидируют идентификаторы и понятнее читаются.

## OR, группы и вложенные условия

```php
Product::where('is_active', true)
    ->orWhere('is_featured', true);
```

Группы условий:

```php
$products = Product::where('category_id', 1)
    ->whereGroup(function ($query) {
        $query->whereLike('title', 'First%')
            ->orWhereLike('title', 'Second%');
    });
```

Можно передать callback прямо в `where()` или `orWhere()`:

```php
$products = Product::where(function ($query) {
    $query->where('category_id', 2)
        ->where('brand_id', 3);
})->orWhere(function ($query) {
    $query->where('category_id', 1)
        ->where(function ($query) {
            $query->where('brand_id', 2)
                ->orWhere('id', 5);
        });
});
```

Внутри callback приходит mutable-прокси, поэтому можно писать `$query->where(...);` без обязательного `return`.

## Списки, NULL, диапазоны и LIKE

```php
Product::whereIn('id', [1, 2, 3]);
Product::orWhereIn('id', [4, 5]);

Product::whereNotIn('id', [6, 7]);
Product::orWhereNotIn('id', [8, 9]);

Product::whereNull('deleted_at');
Product::orWhereNull('deleted_at');

Product::whereNotNull('published_at');
Product::orWhereNotNull('published_at');

Product::whereBetween('price', [100, 500]);
Product::whereNotBetween('price', [100, 500]);

Product::whereLike('title', '%phone%');
Product::orWhereLike('title', '%case%');
```

`whereIn('id', [])` компилируется в заведомо ложное условие (`0 = 1`). `whereNotIn('id', [])` - в заведомо истинное (`1 = 1`).

## Raw-условия

```php
Product::whereRaw('MATCH(title, text) AGAINST (?)', $term);
Product::orWhereRaw('JSON_CONTAINS(tags, ?)', json_encode($tag, JSON_THROW_ON_ERROR));
```

Raw SQL оборачивается в скобки и получает bindings. Массив в binding разворачивается в список placeholder-ов:

```php
Product::whereRaw('id IN (?)', [1, 2, 3]);
```

## SELECT

```php
Product::select('id', 'title');
Product::select('id, title');
Product::select('*');                 // все колонки (звёздочка)
Product::select('distinct title');    // короткий аналог ->distinct()->select('title')
Product::addSelect('category_id');
Product::selectRaw('UPPER(title) AS upper_title');
Product::distinct();
```

`select()` принимает безопасные идентификаторы, `table.column`, `table.*`, голую `*`, а также shorthand `distinct <col>` (префикс `DISTINCT` применяется ко всему списку, как в SQL). Простые aggregate-alias выражения вроде `COUNT(*) AS total` тоже разрешены. Для прочих SQL-функций используйте `selectRaw()`.

Алиасы, которых нет в таблице, доступны как extras:

```php
$product = Product::select('products.*')
    ->addSelect('category.title AS category_title')
    ->join('category')
    ->where('products.id', 1)
    ->firstOrFail();

echo $product->category_title;
```

## Сортировка, группировка, limit

```php
Product::orderBy('title');
Product::orderBy('price', 'desc');
Product::orderByRaw('FIELD(status, ?, ?)', ['new', 'old']);

Product::groupBy('category_id');
Product::groupBy('brand_id', 'category_id');
Product::groupByRaw('DATE(created_at)');

Product::having('COUNT(*) > ?', 2);
Product::havingRaw('SUM(price) > ?', 10000);

Product::limit(20);
Product::offset(40);
```

> **Важно про `having()`.** Структурная форма `having('column', '>', value)` компилируется корректно только для **обычных колонок**. Для aggregate-выражений (`COUNT(*)`, `SUM(price)`, …) `having('COUNT(*)', '>', 2)` соберёт некорректный SQL — в таких случаях всегда используйте **raw-форму** `havingRaw('COUNT(*) > ?', 2)`. Raw-форма (`having('COUNT(*) > ?', 2)` с одним строковым аргументом и `havingRaw(...)`) и является рекомендуемой для условий по агрегатам.

Старый `order_by('title DESC, id ASC')` и `group_by('brand_id, category_id')` поддерживаются для совместимости.

## JOIN

Явный join по колонкам:

```php
$products = Product::join(
    'categories',
    'categories.id',
    '=',
    'products.category_id',
);
```

Join по прямой связи:

```php
$products = Product::join('category')
    ->select('products.*')
    ->addSelect('category.title AS category_title');
```

`join('category')` работает, если связь можно вывести по колонкам: `products.category_id -> categories.id`. Для has-many join используется обратная колонка в целевой таблице.

Доступны также:

```php
Product::leftJoin('categories', 'categories.id', '=', 'products.category_id');
Product::rightJoin('prices', 'prices.product_id', '=', 'products.id');
Product::crossJoin('currencies');
Product::joinSub($subquery, 'price_stats', 'price_stats.product_id', '=', 'products.id');
Product::leftJoinSub($subquery, 'price_stats', 'price_stats.product_id', '=', 'products.id');
```

## Подзапросы

```php
$categoryIds = DB::table('categories')
    ->select('id')
    ->whereLike('title', 'First%');

$products = Product::whereIn('category_id', $categoryIds)
    ->where('id', '>', 1);
```

`queryDependencies()` увидит зависимости и основной таблицы, и таблиц из подзапросов. Это важно для result cache.

## Exists и сравнение колонок

```php
$prices = DB::table('prices')
    ->selectRaw('1')
    ->whereColumn('prices.product_id', '=', 'products.id');

Product::whereExists($prices);
Product::whereNotExists($prices);
Product::whereColumn('products.updated_at', '>=', 'products.created_at');
```

## Получение результата

```php
$first = Product::where('is_active', true)->first();       // Product|null
$first = Product::where('is_active', true)->firstOrFail(); // Product или исключение
$last = Product::where('is_active', true)->last();         // Product|null

Product::where('id', 1)->exists();
Product::where('id', 1)->doesntExist();
Product::where('id', 1)->isEmpty();
Product::where('id', 1)->isNotEmpty();
Product::where('id', 1)->ne(); // alias isNotEmpty()
```

`count` и `only_count` доступны как свойства для compatibility:

```php
Product::all()->count;
Product::all()->only_count;
```

В новом коде лучше явно:

```php
Product::all()->count();
```

## Агрегаты и значения

`sum()`, `avg()`, `min()`, `max()` можно вызывать и на цепочке, и статически (как `where`). `count()` — это метод экземпляра (он же обслуживает интерфейс `Countable`, поэтому работает и PHP-функция `count($model)`), его вызывают на запросе:

```php
Product::all()->count();                       // вся таблица (или count(Product::all()) через Countable)
Product::where('category_id', 5)->count();
Product::where('category_id', 5)->sum('price');
Product::where('category_id', 5)->avg('price');
Product::where('category_id', 5)->min('price');
Product::where('category_id', 5)->max('price');

Product::where('id', 1)->value('title');
Product::where('category_id', 5)->pluck('title');
Product::where('category_id', 5)->pluck('title', 'id');
```

`pluck()` пропускает `null`-значения, если ключ не задан.

## Пагинация

```php
$products = Product::where('is_active', true)
    ->orderBy('id')
    ->paginate(20, page: 0);

foreach ($products as $product) {
    echo $product->title;
}

$products->foundRows();   // общее количество без limit/offset
$products->total();       // alias foundRows()
$products->lastPage();
$products->hasNextPage();
```

Нумерация страниц начинается с `0`. Если страницу не передать, `paginate()` возьмет `$_GET['page'] ?? 0`.

`simplePaginate($perPage, $page)` сейчас является alias `paginate()`.

## Загрузка и состояние загрузки

```php
$products = Product::where('category_id', 5);

$products->isLoaded();      // false
$products->loadedCount();   // 0

$products->load();          // принудительно загрузить все строки

$products->isLoaded();      // true
$products->isFullyLoaded(); // true
$products->loadedCount();   // сколько строк загружено
```

`load()` возвращает тот же ActiveRecord-набор, поэтому его можно использовать в цепочке, когда нужно заранее материализовать результат.

## Большие таблицы

`chunkById()` и `eachById()` тоже работают как статические фабрики (как `where`) или на цепочке:

```php
Product::eachById(500, function (Product $product) {
    // одна строка
});

Product::chunkById(500, function (Product $chunk) {
    foreach ($chunk as $product) {
        // набор строк
    }
});

Product::where('is_active', true)->chunkById(500, function (Product $chunk) {
    // набор строк
});
```

`chunkById()` игнорирует существующий order/limit/offset и идет по primary key, чтобы каждая строка была посещена один раз.

## Диагностика запроса

```php
$query = Product::where('category_id', 5)->orderBy('id');

$query->toSql();
$query->bindings();
$query->toRawSql();          // только для диагностики
$query->queryFingerprint();
$query->queryDependencies();
```

`toRawSql()` подставляет bindings в SQL через `PDO::quote()` и нужен только для чтения человеком. Для выполнения используйте обычный запрос с bindings.

## Условная текучесть

```php
$products = Product::all()
    ->when($categoryId, fn ($query, $id) => $query->where('category_id', $id))
    ->unless($showHidden, fn ($query) => $query->where('is_hidden', false))
    ->tap(fn ($query) => logger($query->toSql()));
```

- `when($value, $callback, $default = null)` вызывает callback для truthy-значения.
- `unless($value, $callback, $default = null)` вызывает callback для falsy-значения.
- `tap($callback)` вызывает callback и возвращает исходный объект.
- `orStub()` возвращает пустой stub, если текущий запрос пустой.

## Поиск по нескольким полям

```php
$products = Product::search(['title', 'text'], 'phone');
```

`search($fields, $term)` добавляет группу `LIKE '%term%'` по одному или нескольким полям и объединяет их через `OR`. Метод доступен через ActiveRecord magic call и удобен как короткий compatibility-хелпер. Для нового доменного поиска часто лучше написать метод модели с понятным именем:

```php
class Product extends ActiveRecord
{
    public static function catalogSearch(string $term): static
    {
        return static::search(['title', 'text'], $term)
            ->where('is_active', true);
    }
}
```

## `copy()` и `resetQuery()`

ActiveRecord-цепочка обычно меняет текущий объект запроса. Если нужен независимый branch:

```php
$base = Product::where('is_active', true);

$cheap = $base->copy()->where('price', '<', 1000);
$expensive = $base->copy()->where('price', '>=', 1000);

$fresh = $base->resetQuery(); // новый пустой Product-запрос
```

Standalone `QueryBuilder` immutable сам по себе; для него `copy()` не нужен.
