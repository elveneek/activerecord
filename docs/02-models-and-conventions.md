# Модели И Соглашения

## Минимум бойлерплейта

Самая частая модель выглядит так:

```php
use Elveneek\ActiveRecord;

class Product extends ActiveRecord {}
```

В этом варианте уже работают:

- таблица `products`;
- primary key `id`;
- `Product::find(1)`;
- `Product::where(...)`;
- прямые связи по колонкам `*_id`;
- автоматические casts для `id`, `*_id` и `is_*`;
- `created_at`, `updated_at`, `sort`, если такие колонки есть.

Ручные настройки нужны не для старта, а для исключений из соглашений.

## Имена таблиц

Имя таблицы строится из короткого имени класса:

| Класс | Таблица |
| --- | --- |
| `Product` | `products` |
| `Category` | `categories` |
| `CatalogProduct` | `catalog_products` |
| `Person` | `people` |

Если таблица называется иначе, переопределите ее явно:

```php
class Product extends ActiveRecord
{
    protected static string $table = 'catalog_products';
}
```

Для нестандартных слов можно добавить правило инфлектора:

```php
use Elveneek\Metadata\Inflector;

Inflector::addRule('criterion', 'criteria');
```

## Типичные колонки

| Колонка | Обязательна | Что дает |
| --- | --- | --- |
| `id` | Почти всегда да | `find()`, `findMany()`, identity map, сохранение существующих строк, связи |
| `sort` | Нет | Если есть и при вставке пустая, после insert получит значение `id` |
| `created_at` | Нет | Если есть и не задана при insert, заполнится текущим временем |
| `updated_at` | Нет | Если есть, обновляется при insert и update |
| `*_id` | Для связей | Автоматический int cast и соглашение для belongs-to/has-many |
| `is_*` | Нет | Автоматический bool cast |

Если выбрать проекцию без primary key, такую строку можно читать, но нельзя сохранить как обычную модель:

```php
$product = Product::select('title')->where('id', 1)->firstOrFail();
$product->title = 'New';

$product->save(); // ReadOnlyRecordException
```

Для изменяемых моделей выбирайте `id` или все колонки.

## Статические настройки модели

Все настройки ниже необязательны. Их стоит добавлять только когда соглашения не подходят.

```php
class Product extends ActiveRecord
{
    protected static string $table = 'catalog_products';
    protected static string $primaryKey = 'product_id';
    protected static string $defaultOrder = 'sort';
    protected static ?string $versionColumn = 'lock_version';
}
```

| Свойство | По умолчанию | Когда нужно |
| --- | --- | --- |
| `$table` | plural snake-case от класса | Таблица не совпадает с именем модели |
| `$primaryKey` | `id` | Primary key называется иначе |
| `$defaultOrder` | нет | Все запросы модели должны иметь базовую сортировку |
| `$versionColumn` | нет | Нужен optimistic lock |
| `$casts` | соглашения | Нужно явно приводить типы |
| `$fillable` | не задан | Нужно безопасно принимать массив в `fill()` |
| `$hidden` | `[]` | Нужно скрыть поля из `toArray()`/JSON |
| `$visible` | `[]` | Нужен строгий whitelist сериализации |
| `$appends` | `[]` | Нужно добавить accessor-поля в `toArray()`/JSON |

Важно: `$fillable`, `$hidden`, `$visible`, `$appends`, `$casts` не нужны для обычного старта. Они решают конкретные задачи и поэтому подробно вынесены в отдельные разделы.

## Primary key

Primary key по умолчанию - `id`. Переопределять его приходится редко:

```php
class Product extends ActiveRecord
{
    protected static string $primaryKey = 'product_id';
}
```

От primary key зависят `find()`, `findMany()`, `by_id()`, identity map, сохранение существующей строки, delete одной строки и optimistic lock.

## Default order

```php
class Product extends ActiveRecord
{
    protected static string $defaultOrder = 'sort';
}
```

Каждый новый запрос модели получит `ORDER BY sort`. Это удобно для справочников, меню, категорий и любых сущностей, где порядок почти всегда один и тот же.

Если нужно убрать сортировку в конкретном запросе, используйте builder-метод и вернитесь в модель:

```php
$query = Product::all()
    ->toQuery()
    ->withoutOrder();

$products = Product::fromQuery($query);
```

В обычном ActiveRecord API чаще проще задать явный `orderBy()`, который добавит сортировку к текущей. Если нужно именно заменить default order, работайте через `toQuery()`/`fromQuery()`.

## Strict mode

По умолчанию неизвестное поле или невыбранная колонка возвращают `null`. Это совместимо со старым стилем:

```php
echo Product::select('id')->find(1)->title; // null
```

В strict mode ошибки становятся явными:

```php
Product::strictMode(true);

Product::select('id')->find(1)->title;
// MissingAttributeException: поле есть в таблице, но не было выбрано

Product::find(1)->unknown_field;
// UnknownAttributeOrRelationException
```

Strict mode полезен в новом коде и тестах, когда молчаливый `null` скрывает ошибку.

## Schema mode

У ActiveRecord есть compatibility-поведение: если при сохранении задано поле, которого нет в таблице, библиотека может создать колонку через `Scaffold`.

Режимы:

```php
use Elveneek\SchemaMode;

Product::schemaMode(SchemaMode::Evolve);  // разрешить создание отсутствующих колонок
Product::schemaMode(SchemaMode::Strict);  // не менять схему
Product::schemaMode(SchemaMode::Suggest); // сейчас также не меняет схему
```

`Evolve` удобен в прототипах и старом Elveneek-коде. Для production обычно лучше `Strict` и нормальные миграции.

Низкоуровневый переключатель того же поведения:

```php
Product::schemaEvolution(false); // не создавать отсутствующие колонки
Product::schemaEvolution(true);  // разрешить auto-create
```

Правила auto-create в `Scaffold::create_field()`:

| Имя поля | Тип |
| --- | --- |
| `*_id`, `sort` | `int NULL` |
| `is_*` | `tinyint(4) NOT NULL DEFAULT 0` |
| `*_at` | `datetime NULL` |
| остальное | `text NULL` |

## `fromTable()`

Можно создать модель по имени таблицы:

```php
$products = ActiveRecord::fromTable('products');
```

Метод ожидает, что существует класс для этой таблицы (`Product`) и что он наследуется от `ActiveRecord`. Если класса нет, будет `MissingModelClassException`.

Второй аргумент добавляет суффикс к имени класса:

```php
$products = ActiveRecord::fromTable('products', 'Archive');
// ожидает класс ProductArchive
```

## Схема и колонки

```php
$columns = Product::schemaColumns('products');
$fresh = Product::schemaColumns('products', refresh: true);

$model = Product::all();
$ownColumns = $model->columns();
$otherColumns = $model->columns('categories');
```

`schemaColumns()` кэширует метаданные колонок по соединению и таблице. Сбросить кэш можно так:

```php
Product::flushSchemaCache();
```
