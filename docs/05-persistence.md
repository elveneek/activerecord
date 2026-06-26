# Сохранение И Удаление

ActiveRecord не заставляет разделять "builder", "repository" и "entity". Вы получили строку, поменяли поле, вызвали `save()`:

```php
$product = Product::findOrFail(1);
$product->title = 'New title';
$product->save();
```

## Новая строка

```php
$product = Product::create();
$product->title = 'New product';
$product->price = 1500;
$product->save();
```

Можно сразу передать доверенный массив:

```php
$product = Product::create([
    'title' => 'New product',
    'price' => 1500,
])->save();
```

`create()` только создает new-модель в памяти. SQL `INSERT` выполняется при `save()` или `saveAll()`.

`insert()` - короткая форма `create(...)->save()`:

```php
$product = Product::insert([
    'title' => 'New product',
    'price' => 1500,
]);

echo $product->id;
echo $product->insert_id;
echo $product->ioi(); // insert_id или primary key
```

После insert primary key приводится к `int`, если значение похоже на число.

## `$fillable` защищает только `fill()`

`fill()` нужен для пользовательского ввода:

```php
class Product extends ActiveRecord
{
    protected static array $fillable = ['title', 'price'];
}

$product = Product::create()
    ->fill($request)
    ->save();
```

Правила:

- если `only` не передан, `fill()` берет список из `$fillable`;
- поля вне списка молча игнорируются;
- если `$fillable` не объявлен и `only` не передан, будет `MassAssignmentException`;
- `forceFill()` обходит защиту и подходит только для доверенных данных.

```php
$product->fill($request, only: ['title', 'price']);
$product->forceFill($trustedData);
```

Важно: `create([...])`, `insert([...])`, прямое присваивание, `setRaw()` и `forceFill()` не фильтруются через `$fillable`. Не передавайте сырой HTTP request в `create()` или `insert()`.

## Сохранение одной строки

```php
$product = Product::findOrFail(1);
$product->title = 'Updated';

$product->save();
```

`save()` сохраняет одну dirty/new строку. Если вызвать `save()` на загруженном наборе, где изменено больше одной строки, будет `AmbiguousWriteException`:

```php
$products = Product::whereIn('id', [1, 2])->orderBy('id');

$products[0]->title = 'One';
$products[1]->title = 'Two';

$products->save(); // AmbiguousWriteException
```

Для набора используйте `saveAll()`, для row-bound объекта - `saveCurrent()`:

```php
$products[0]->saveCurrent();
$products->saveAll();
```

## Сохранение набора

```php
$products = Product::where('category_id', 5)->orderBy('id');

foreach ($products as $product) {
    $product->is_active = true;
}

$products->saveAll();
```

`saveAll()` сохраняет все загруженные dirty/new строки в транзакции. Это не один SQL-запрос, а безопасная пачка операций: если одна строка падает, база откатывается, а in-memory состояния возвращаются к исходному виду.

После сохранения:

```php
$products->affectedRows();
$products->lastSaveErrors();
```

`affectedRows()` возвращает количество затронутых строк по последней операции сохранения. `lastSaveErrors()` заполняется при ошибке `saveAll()`.

## Пакетная вставка

```php
$products = Product::create()
    ->addRow(['title' => 'Draft A'])
    ->addRow(['title' => 'Draft B'])
    ->saveAll();
```

`addRow()` добавляет новую строку в текущую коллекцию. Если `create()` создал пустую placeholder-строку, первый `addRow([...])` заполнит ее, а не создаст лишнюю пустую запись.

Короткая форма:

```php
Product::insertAll([
    ['title' => 'Draft A'],
    ['title' => 'Draft B'],
]);
```

## Timestamps

Если в таблице есть `created_at`, при insert она заполнится текущим временем, если вы не передали значение явно.

Если в таблице есть `updated_at`, она заполнится при insert и обновится при update, если вы не передали значение явно.

```php
$product = Product::create(['title' => 'Timestamp test'])->save();

echo $product->created_at;
echo $product->updated_at;
```

`*_at` поля по умолчанию остаются строками. Если нужен `DateTimeImmutable`, задайте cast `datetime` или `date`.

Пустая строка для поля `*_at` превращается в `null`:

```php
$product->published_at = '';
```

## `sort`

Если в таблице есть `sort` и при insert оно пустое, ActiveRecord после вставки выставит `sort = id`:

```php
$product = Product::create(['title' => 'Sorted'])->save();

echo $product->sort; // равно id
```

Если передать непустое значение, оно сохранится:

```php
$product = Product::create([
    'title' => 'Sorted',
    'sort' => 100,
])->save();
```

## `SQL_NULL`

В проекте определена константа `SQL_NULL`. При присваивании она превращается в `null`:

```php
$product->text = SQL_NULL;
$product->save();
```

Это compatibility-инструмент для старого кода. В новом коде обычно достаточно обычного `null`.

## Bulk-операции без гидратации

Если не нужно загружать модели, используйте bulk-методы:

```php
$affected = Product::whereNull('category_id')
    ->updateAll(['is_orphan' => true]);

$deleted = Product::where('is_deleted', true)
    ->deleteAll();

Product::where('id', 1)->increment('views');
Product::where('id', 1)->decrement('stock', 1);
```

`updateAll()` и `deleteAll()` работают по текущему `WHERE`, не создают модели и инвалидируют кэш таблицы. Они не вызывают accessors/mutators и не добавляют `updated_at` автоматически. Если timestamp нужен, задайте его сами:

```php
Product::where('id', 1)->updateAll([
    'title' => 'Changed',
    'updated_at' => DB::now(),
]);
```

`deleteAll()` без условий удалит все строки таблицы. Для полной очистки явнее использовать `truncate(true)`.

## Удаление одной строки

```php
$product = Product::findOrFail(1);
$product->delete();
```

`delete()` требует ровно одну строку. Если вызвать его на наборе из нескольких строк, будет `AmbiguousWriteException`.

Row-bound объект безопасен:

```php
$product = Product::where('category_id', 1)
    ->orderBy('id')
    ->firstOrFail();

$product->delete();
```

После удаления строка помечается как deleted, identity map инвалидируется, а повторный `findOrNull($id)` вернет `null`.

## Truncate

```php
Product::truncate(true);
```

Без явного `true` метод бросит исключение. Это защита от случайной очистки таблицы.

## `firstOrCreate()`

```php
$product = Product::firstOrCreate(
    ['sku' => 'phone-1'],
    ['title' => 'Phone', 'price' => 1500],
);
```

Если строка по `$where` найдена, вернется она. Если нет, будет создана строка из `array_merge($where, $values)` и сохранена.

## `updateOrCreate()`

```php
$product = Product::updateOrCreate(
    ['sku' => 'phone-1'],
    ['title' => 'Phone updated', 'price' => 1700],
);
```

Если строка найдена, она получит `forceFill($values)->save()`. Если нет, будет создана из `$where`, затем заполнена `$values` и сохранена.

## `upsert()`

```php
$count = Product::upsert(
    [
        ['sku' => 'one', 'title' => 'One', 'quantity' => 1],
        ['sku' => 'two', 'title' => 'Two', 'quantity' => 2],
    ],
    uniqueBy: ['sku'],
    update: ['title', 'quantity'],
);
```

`upsert()` строит MySQL `INSERT ... ON DUPLICATE KEY UPDATE`. В базе должен быть реальный unique key. `uniqueBy` используется для проверки входа и документации намерения, а конфликт определяет MySQL по индексам.

Ограничения:

- `uniqueBy` не может быть пустым;
- все строки должны иметь одинаковый набор колонок;
- колонки из `uniqueBy` и `update` должны присутствовать во входных строках.

## Raw expressions

```php
$product->updated_at = DB::now();
$product->save();

Product::where('id', 1)->updateAll([
    'views' => DB::raw('views + ?', [1]),
]);
```

`DB::raw()` возвращает SQL-выражение с bindings. После сохранения raw-выражения, если нужно получить вычисленное базой значение, перечитайте строку через `refresh()` или новый `find()`.

## Optimistic lock

Если в модели задана колонка версии:

```php
class Product extends ActiveRecord
{
    protected static ?string $versionColumn = 'lock_version';
}
```

При update ActiveRecord добавит условие:

```sql
WHERE id = ? AND lock_version = ?
```

и увеличит `lock_version` на 1. Если другая транзакция успела изменить строку, `rowCount()` будет `0`, и сохранение завершится `StaleModelException`.

## Нельзя менять запрос грязной коллекции

Если коллекция уже материализована и в ней есть несохраненные изменения, библиотека не даст превратить ее в другой запрос:

```php
$products = Product::orderBy('id')->load();
$products[0]->title = 'Dirty';

$products->where('price', '>', 1000);
// DirtyResultCannotBeRequeriedException
```

Это защищает от тихой потери изменений.
