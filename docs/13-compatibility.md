# Compatibility API

В проекте сохранены старые имена методов и свойств. Они полезны при поддержке существующего кода, но в новой документации основной путь показан через camelCase API.

## Алиасы методов

| Старое имя | Современный эквивалент |
| --- | --- |
| `order_by()` | `orderBy()` |
| `group_by()` | `groupBy()` |
| `and_select()` | `addSelect()` |
| `find_by($field, $value)` | `where($field, $value)` |
| `w()`, `_w()`, `_where()` | `where()` |
| `f()`, `_f()` | `findOne()`/`find()` |
| `to_array()` | `toArray()` |
| `to_json()` | `toJson()` |
| `all_of($field)` | `pluck($field)` |
| `found_rows()` | `foundRows()` |
| `linked($table)` | `related('_' . $table)` |
| `all_linked($relation)` | `allLinked($relation)` |
| `saveOne()` | `saveCurrent()` |
| `presave_row()` | `addRow()` |

Примеры:

```php
Product::find_by('id', 1)->title;
Product::w('category_id', 5)->all_of('title');

Product::all()->order_by('title DESC, id ASC');
```

`order_by()` специально понимает legacy SQL вроде `rand()` или `title DESC, id ASC`. Новый `orderBy()` валидирует имя колонки и направление.

`group_by('brand_id, category_id')` разбивает строку по запятой.

## Алиасы свойств

Некоторые терминальные операции доступны как свойства:

| Свойство | Эквивалент |
| --- | --- |
| `$products->count` | `$products->count()` |
| `$products->only_count` | `$products->count()` |
| `$products->isEmpty` | `$products->isEmpty()` |
| `$products->isNotEmpty` | `$products->isNotEmpty()` |
| `$products->ne` | `$products->isNotEmpty()` |
| `$products->to_array` | `$products->toArray()` |
| `$products->to_json` | `$products->toJson()` |
| `$products->stub` | `$products->stub()` |
| `$model->table` | имя таблицы модели |

Пример:

```php
if (Product::where('id', 1)->ne) {
    echo Product::find(1)->to_json;
}
```

В новом коде лучше методы: они заметнее выполняют работу.

## Stub

```php
$empty = Product::stub();
$empty = Product::where('id', 1)->stub;
```

`stub()` добавляет условие `0 = 1`, поэтому результат всегда пустой.

Это удобно в старом коде, где нужно вернуть ActiveRecord-набор, но без строк:

```php
return $userCanSee ? Product::all() : Product::stub();
```

## `get()` и `only()`

```php
$value = $product->get('title');
$value = Product::where('id', 1)->only('title');
```

`get($field)` просто читает свойство. Второй аргумент `$multilang` сохранен в сигнатуре для compatibility и сейчас не меняет поведение.

`only($field)` - alias `value($field)`.

## `SQL_NULL`

```php
$product->text = SQL_NULL;
$product->save();
```

`SQL_NULL` превращается в `null` при присваивании. В новом коде обычно проще писать `$product->text = null`.

## Старые public static поля

В `ActiveRecord` остаются public static поля для совместимости:

```php
ActiveRecord::$_queries_cache;
ActiveRecord::$_columns_cache;
ActiveRecord::$preparedStatements;
```

Новый код не должен напрямую работать с ними. Для управления текущими кэшами используйте:

```php
ActiveRecord::flushIdentityCache();
ActiveRecord::flushSchemaCache();
ActiveRecord::invalidateTableCache('products');
```

`$_columns_cache` синхронизируется с `schemaColumns()` для старого кода. `$_queries_cache` и `$preparedStatements` не являются основным публичным API новой реализации.

## Старые plural helpers

```php
ActiveRecord::one_to_plural('category'); // categories
ActiveRecord::plural_to_one('categories'); // category
```

Современный низкоуровневый класс:

```php
use Elveneek\Metadata\Inflector;

Inflector::plural('category');
Inflector::singular('categories');
Inflector::snake('CatalogProduct');
```

## `fromTable()`

```php
ActiveRecord::fromTable('products')->w('id', 1)->title;
```

Метод строит имя класса из singular-имени таблицы. Для `products` ожидается `Product`.

Если класс лежит в namespace, `fromTable()` все равно ищет глобальный класс по старому соглашению. Для namespaced-кода обычно лучше использовать конкретную модель напрямую.

## Legacy raw where

Старый стиль:

```php
Product::where('id < ? AND id > ?', 4, 2);
Product::where('title LIKE ?', '%product%');
Product::where('id IN (?)', [1, 3, 5]);
```

Новый структурный стиль:

```php
Product::where('id', '<', 4)
    ->where('id', '>', 2);

Product::whereLike('title', '%product%');
Product::whereIn('id', [1, 3, 5]);
```

Оба работают. Структурный стиль безопаснее: он валидирует имена колонок и операторы.

## Scaffold

```php
Scaffold::create_field('products', 'runtime_note');
Scaffold::rename_column('products', 'old_title', 'new_title');
Scaffold::create_table('products');
Scaffold::create_table('products', 'category');
```

`Scaffold` - legacy schema API. Он используется при `SchemaMode::Evolve`, когда сохранение встречает отсутствующую колонку.

Для production предпочтительнее миграции и:

```php
Product::schemaMode(SchemaMode::Strict);
```
