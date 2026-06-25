# Query Builder

`QueryBuilder` - самостоятельный низкоуровневый конструктор SQL. Он нужен для отчетов, подзапросов, сырых выборок и случаев, где не нужна гидратация ActiveRecord-моделей.

```php
use Elveneek\DB;

$query = DB::table('products')
    ->select('id', 'title')
    ->where('category_id', 5)
    ->orderBy('id');

$rows = $query->rows(); // list<stdClass>
```

## Это не ActiveRecord

`DB::table()` возвращает `Elveneek\Query\QueryBuilder`, а не модель:

```php
$query = DB::table('products');

$query instanceof Product; // false
```

Терминальные методы builder-а возвращают `stdClass`, массивы или скаляры. Casts модели, accessors, mutators, identity map и relation API не применяются.

Если нужны модели, используйте `Product::where(...)` или `Product::fromQuery($query)`.

## Immutable-дизайн

Query Builder immutable: каждый метод возвращает новый builder и не меняет старый.

```php
$base = DB::table('products')->where('category_id', 1);
$narrow = $base->where('id', 2);

$base->bindings();   // [1]
$narrow->bindings(); // [1, 2]
```

Это удобно для branch-запросов и подзапросов.

ActiveRecord-цепочки ведут себя иначе: они обычно меняют текущий ActiveRecord-запрос и возвращают `static`, чтобы писать коротко. Если нужен branch на ActiveRecord, используйте `copy()`.

## Построение SELECT

```php
DB::table('products')->select('id', 'title');
DB::table('products')->select('id, title');
DB::table('products')->addSelect('category_id');
DB::table('products')->selectRaw('UPPER(title) AS upper_title');
DB::table('products')->distinct();
```

`select()` валидирует идентификаторы. Для намеренного SQL используйте `selectRaw()`.

## WHERE

```php
DB::table('products')->where('title', 'Phone');
DB::table('products')->where('price', '>=', 1000);
DB::table('products')->where(['category_id' => 5, 'is_active' => true]);
DB::table('products')->where('price > ? AND stock > ?', 1000, 0);

DB::table('products')->orWhere('is_featured', true);

DB::table('products')->whereGroup(function ($query) {
    $query->whereLike('title', 'First%')
        ->orWhereLike('title', 'Second%');
});
```

Доступны:

```php
where()
orWhere()
whereGroup()
orWhereGroup()
whereIn()
orWhereIn()
whereNotIn()
orWhereNotIn()
whereNull()
orWhereNull()
whereNotNull()
orWhereNotNull()
whereBetween()
whereNotBetween()
whereLike()
orWhereLike()
whereExists()
whereNotExists()
whereColumn()
whereRaw()
orWhereRaw()
whereKey()
whereKeys()
```

`whereColumn($left, $operator, $right)` поддерживает только операторы `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`.

## JOIN

```php
DB::table('products')->join(
    'categories',
    'categories.id',
    '=',
    'products.category_id',
);

DB::table('products')->leftJoin('categories', 'categories.id', '=', 'products.category_id');
DB::table('products')->rightJoin('prices', 'prices.product_id', '=', 'products.id');
DB::table('products')->crossJoin('currencies');
```

Подзапрос в join:

```php
$prices = DB::table('prices')
    ->select('product_id')
    ->selectRaw('MAX(value) AS max_price')
    ->groupBy('product_id');

$products = DB::table('products')
    ->joinSub($prices, 'price_stats', 'price_stats.product_id', '=', 'products.id');
```

`leftJoinSub()` делает left join subquery.

## GROUP, HAVING, ORDER, LIMIT

```php
DB::table('products')->groupBy('category_id');
DB::table('products')->groupBy('brand_id', 'category_id');
DB::table('products')->groupByRaw('DATE(created_at)');

DB::table('products')->having('COUNT(*) > ?', 2);
DB::table('products')->havingRaw('SUM(price) > ?', 10000);

DB::table('products')->orderBy('title', 'desc');
DB::table('products')->orderByRaw('RAND()');

DB::table('products')->limit(20);
DB::table('products')->offset(40);
```

`limit()` и `offset()` не принимают отрицательные значения.

Служебные методы для копий запроса:

```php
$query = DB::table('products')->orderBy('title')->limit(10);

$withoutOrder = $query->withoutOrder();
$withoutPage = $query->withoutLimitOffset();
```

Они полезны для count-запросов, chunking и ручного ветвления builder-а.

## Кэш и блокировки

Builder хранит настройки, которые ActiveRecord потом использует:

```php
DB::table('products')->remember(60);
DB::table('products')->rememberForever('all-products');
DB::table('products')->withoutCache();
DB::table('products')->withoutIdentityMap();
```

Если builder используется напрямую через `rows()`, result cache ActiveRecord не применяется. `remember()` полезен, когда builder будет передан в `Product::fromQuery()` или когда вы работаете через ActiveRecord API.

Блокировки компилируются в SQL:

```php
DB::table('products')->where('id', 1)->lockForUpdate();
DB::table('products')->where('id', 1)->sharedLock();
```

В MySQL это `FOR UPDATE` и `LOCK IN SHARE MODE`.

## SQL и bindings

```php
$query = DB::table('products')
    ->where('category_id', 5)
    ->whereLike('title', 'Phone%');

$query->toSql();
$query->bindings();
$query->fingerprint();
$query->dependencies();
```

`dependencies()` возвращает таблицы, от которых зависит запрос. Они используются для инвалидации result cache в ActiveRecord.

## Терминальные методы

```php
$rows = DB::table('products')->rows();        // list<stdClass>
$row = DB::table('products')->firstRow();     // stdClass|null
$title = DB::table('products')->value('title');
$titles = DB::table('products')->column('title');

$count = DB::table('products')->count();
$exists = DB::table('products')->exists();

$sum = DB::table('products')->sum('price');
$avg = DB::table('products')->avg('price');
$min = DB::table('products')->min('price');
$max = DB::table('products')->max('price');
```

`firstRow()` добавляет `LIMIT 1`.

## `toQuery()` и `fromQuery()`

Из ActiveRecord можно получить builder:

```php
$builder = Product::where('is_active', true)->toQuery();
$expensive = $builder->where('price', '>=', 1000);
```

Можно вернуться обратно в модель:

```php
$products = Product::fromQuery(
    DB::table('products')
        ->select('products.*')
        ->where('is_active', true)
);
```

`fromQuery()` принимает только совместимый запрос:

- таблица builder-а должна совпадать с таблицей модели;
- запрос должен представлять writable rows;
- если выбраны не все колонки, должен быть выбран primary key;
- grouped/aggregate query нельзя превратить в изменяемые модели.

Иначе будет `IncompatibleQueryException`.

## Raw expressions

```php
DB::raw('CURRENT_TIMESTAMP');
DB::now();
```

`DB::raw($sql, $bindings)` возвращает `Elveneek\Raw`, наследника `Expression`.

Где это полезно:

```php
Product::where('id', 1)->updateAll([
    'views' => DB::raw('views + ?', [1]),
    'updated_at' => DB::now(),
]);
```

Для raw-частей builder-а обычно используются специальные методы:

```php
selectRaw()
whereRaw()
orWhereRaw()
groupByRaw()
havingRaw()
orderByRaw()
```

## Безопасность идентификаторов

Builder валидирует имена таблиц, колонок, aliases и операторы в структурных методах.

Такой код будет отклонен:

```php
DB::table('products')->orderBy('id; DROP TABLE products');
```

Если нужен настоящий SQL-фрагмент, используйте raw-метод и bindings:

```php
DB::table('products')->orderByRaw('FIELD(status, ?, ?)', ['new', 'old']);
```

Правило простое: данные всегда bindings, идентификаторы - через структурные методы, произвольный SQL - только там, где вы явно написали `Raw`.
