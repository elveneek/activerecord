# Elveneek ActiveRecord

Elveneek ActiveRecord - маленькая ORM для случаев, когда хочется начать с модели и сразу работать с таблицей:

```php
use Elveneek\ActiveRecord;

class Product extends ActiveRecord {}

echo Product::find(1)->price;

$products = Product::where('is_active', true)
    ->where('price', '>=', 1000)
    ->orderBy('sort')
    ->limit(20);

foreach ($products as $product) {
    echo $product->title;
}
```

По умолчанию модель почти не требует настройки. `Product` ищет таблицу `products`, `Category` - `categories`, `products.category_id` дает `$product->category`, а `categories` получают `$category->products`, если в `products` есть `category_id`.

Главная идея: сначала соглашения и простота, а ручные настройки только там, где они действительно нужны.

## Что читать сначала

1. [Быстрый старт](01-quick-start.md) - подключение, первая модель, ленивые запросы и что возвращают основные методы.
2. [Модели и соглашения](02-models-and-conventions.md) - имена таблиц, типичные колонки, статические настройки, strict/schema mode.
3. [Основной API запросов](03-query-api.md) - `where`, `select`, `orderBy`, `first`, `count`, пагинация, диагностические методы.
4. [Связи и pivot](04-relations.md) - прямые связи по колонкам, явный проход через pivot-модель, explicit `belongsToMany()`.
5. [Сохранение и удаление](05-persistence.md) - `create`, `save`, `saveAll`, bulk-операции, timestamps, optimistic lock.
6. [Коллекции, состояние и JSON](06-serialization-and-state.md) - `foreach`, `ArrayAccess`, dirty tracking, `toArray`, `$hidden`, `$visible`, `$appends`.
7. [Типы, accessors, mutators и formatters](07-casts-mutators-formatters.md) - casts, `getTitle()`, `setTitle()`, `_as_`.
8. [Кеш](08-cache.md) - identity map, result cache, relation cache, schema cache и инвалидация.
9. [Query Builder](09-query-builder.md) - самостоятельный низкоуровневый builder через `DB::table()`.
10. [Методы модели вместо scopes](10-model-methods-not-scopes.md) - почему отдельных scope-правил нет и как писать именованные цепочки.
11. [DB и транзакции](11-db-and-transactions.md) - подключение, query log, `transaction()`, `afterCommit()`.
12. [Карта классов и файлов](12-class-map.md) - какой файл за что отвечает.
13. [Compatibility API](13-compatibility.md) - старые алиасы и совместимость.

## Фасады, value objects и результаты

`ActiveRecord` - главный публичный фасад модели. Один объект может быть ленивым запросом, коллекцией строк или row-bound моделью конкретной строки. Это сделано специально: можно писать коротко, а библиотека догружает данные в момент чтения.

`DB` - статический сервис для подключения, resolver внешнего `PDO`, транзакций, raw-выражений, query log и создания standalone query builder.

`QueryBuilder` - не фасад и не модель. Это immutable value object, который знает SQL, bindings и зависимости таблиц. Он возвращает `stdClass`-строки, если использовать его напрямую через `DB::table()`.

`RelationManager` и `RelationDefinition` - объекты управления связями. Автоматически найденная связь умеет читать, `associate()` и `dissociate()` для прямой belongs-to. Pivot-запись (`attach`, `detach`, `sync`) доступна только у явно объявленной `belongsToMany()`.

## Короткий пример целиком

```php
use Elveneek\ActiveRecord;
use Elveneek\DB;

DB::setConnection(ActiveRecord::connect());

class Product extends ActiveRecord {}
class Category extends ActiveRecord {}
class Brand extends ActiveRecord {}
class Categories_to_product extends ActiveRecord {}

$product = Product::findOrFail(1);
echo $product->title;
echo $product->category->title;

$brands = Category::find(1)
    ->_categories_to_products
    ->_products
    ->_brands;

foreach ($brands as $brand) {
    echo $brand->title;
}
```

В этом примере не объявлено ни одной связи вручную. Прямые связи находятся по колонкам, а pivot-переход сделан явно через модель промежуточной таблицы `Categories_to_product`.
