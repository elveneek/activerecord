# Generic Table Models

Generic table model позволяет работать с таблицей без отдельного PHP-класса модели. Это поведение включено по умолчанию.

```php
use Elveneek\ActiveRecord;

$title = ActiveRecord::fromTable('products')
    ->where('id', 1)
    ->title;
```

Если для таблицы есть обычный класс ActiveRecord, будет использован он:

```php
class Product extends ActiveRecord {}

$product = ActiveRecord::fromTable('products'); // Product
```

Если класса нет или имя занято чужим классом, который не наследует `Elveneek\ActiveRecord`, будет создан внутренний `Elveneek\TableRecord`, привязанный к имени таблицы:

```php
class Product extends SomeOtherOrmModel {}

$product = ActiveRecord::fromTable('products'); // Elveneek\TableRecord для таблицы products
```

## Явная Привязка Таблицы К Классу

Если имя `Product` уже занято системной ORM, но для таблицы `products` нужна своя ActiveRecord-модель с методами, привяжите таблицу явно:

```php
use Elveneek\ActiveRecord;

class ProductRecord extends ActiveRecord
{
    //Произвольный пользовательский метод
    public function displayTitle(): string
    {
        return '[' . $this->id . '] ' . $this->title;
    }
}

ActiveRecord::mapTable('products', ProductRecord::class);

$product = ProductRecord::findOrFail(1); // таблица products
$sameProduct = ActiveRecord::fromTable('products')->findOrFail(1); // ProductRecord
echo $product->displayTitle();
```

`mapTable()` имеет приоритет над конвенцией имени (`Product`) и над generic fallback. Класс `ProductRecord` не обязан объявлять `protected static string $table = 'products';`: привязка таблицы передается самой ActiveRecord-моделью.

Такая привязка работает и внутри автоматических связей:

```php
$product = Category::find(1)
    ->products
    ->limit(1);

$product instanceof ProductRecord; // true
```

Если нужно убрать привязку в тесте или bootstrap-коде, используйте `ActiveRecord::unmapTable('products')` или `ActiveRecord::clearTableMap()`.

## Связи Без Классов

Generic-модели участвуют в автоматических связях так же, как обычные модели.

```php
class Category extends ActiveRecord {}

$title = Category::find(1)
    ->products
    ->limit(1)
    ->title;
```

Если `Product` не существует как ActiveRecord-класс, relation `products` вернет generic-модель таблицы `products`. Для has-many связи достаточно, чтобы в `products` была колонка `category_id`.

Однострочный обход без объявления классов тоже работает:

```php
$title = ActiveRecord::fromTable('categories')
    ->find(1)
    ->products
    ->limit(1)
    ->title;
```

## Зачем Это Нужно

Generic table model заменяет старый подход с runtime `eval("class Product extends ActiveRecord {}")`. PHP-класс не создается, глобальное имя `Product` не занимается, а конфликт с существующим системным классом `Product` не мешает читать таблицу `products` через ActiveRecord.

Это удобно для постепенной миграции старых проектов:

```php
$arProduct = ActiveRecord::fromTable('products')->findOrFail($id);
$systemProduct = SystemProduct::find($arProduct->id);
```

Когда таблице понадобится бизнес-логика, casts, accessors или явные relations, можно позже добавить настоящий класс ActiveRecord. Если имя по конвенции свободно, он автоматически станет приоритетнее generic-модели. Если имя занято другой ORM, используйте `mapTable()`.

## Кеши И Идентичность

Все generic-модели используют один PHP-класс `Elveneek\TableRecord`, но внутренний ключ модели включает имя таблицы. Поэтому `categories.id = 1` и `products.id = 1` не смешиваются в identity map, result cache и query fingerprint.

```php
$category = ActiveRecord::fromTable('categories')->findOrFail(1);
$product = ActiveRecord::fromTable('products')->findOrFail(1);
```

Обе записи могут иметь одинаковый primary key, но будут храниться как разные model identities.

## Ограничения

Generic-модель не заменяет полноценный класс, если нужна модельная логика. У нее нет собственных `$casts`, `$hidden`, `$visible`, `$appends`, accessors, mutators и явно объявленных relations.

Для простого чтения, записи стандартных колонок и автоматических belongs-to/has-many связей generic-модель подходит. Для предметной логики лучше создать обычный класс:

```php
class Product extends ActiveRecord
{
    protected static array $casts = [
        'payload' => 'json',
    ];
}
```