# Типы, Mutators И Formatters

## Типы по соглашениям

Даже без `$casts` ActiveRecord приводит самые частые поля:

| Поле | Тип при чтении |
| --- | --- |
| primary key (`id` по умолчанию) | `int` |
| `*_id` | `int` |
| `is_*` | `bool` |

Пример:

```php
$product = Product::findOrFail(1);

is_int($product->id);          // true
is_int($product->category_id); // true
is_bool($product->is_active);  // true, если колонка есть
```

`*_at` поля автоматически не превращаются в даты. По умолчанию они остаются строками, чтобы старый код не менял поведение неожиданно.

## Явные casts

```php
class Product extends ActiveRecord
{
    protected static array $casts = [
        'id' => 'int',
        'price' => 'decimal:2',
        'weight' => 'float',
        'is_active' => 'bool',
        'settings' => 'json',
        'tags' => 'array',
        'published_at' => 'datetime',
        'publish_date' => 'date',
        'state' => ProductState::class,
    ];
}
```

Поддерживаются:

| Cast | Результат при чтении | Запись в БД |
| --- | --- | --- |
| `int`, `integer` | `int` | значение как integer |
| `float`, `double`, `real` | `float` | значение как float/string binding |
| `decimal:N` | строка с N знаками | строка с N знаками |
| `bool`, `boolean` | `bool` | boolean binding |
| `string` | `string` | строка |
| `json`, `array` | `array` | JSON-строка |
| `datetime` | `DateTimeImmutable` | `Y-m-d H:i:s` |
| `date` | `DateTimeImmutable` | `Y-m-d` |
| backed enum class | enum case | backing value |
| object caster | результат `get()` | результат `set()` |

Явный cast имеет приоритет над соглашениями.

## Decimal

`decimal:N` возвращает строку, а не `float`:

```php
class MoneyRecord extends ActiveRecord
{
    protected static array $casts = [
        'amount' => 'decimal:10',
    ];
}
```

Это сохраняет большие значения без потери точности:

```php
echo $record->amount; // "12345678901234567890.1234567890"
```

При записи значение округляется до заданного scale.

## JSON и array

```php
class Product extends ActiveRecord
{
    protected static array $casts = [
        'settings' => 'json',
    ];
}

$product->settings = ['color' => 'black', 'sizes' => ['M', 'L']];
$product->save();

$fresh = Product::findOrFail($product->id);

$fresh->settings['color']; // black
```

Невалидный JSON при чтении выбросит `JsonException`.

## Date и datetime

```php
class Event extends ActiveRecord
{
    protected static array $casts = [
        'started_at' => 'datetime',
        'event_date' => 'date',
    ];
}

$event->started_at = new DateTimeImmutable('2026-06-24 12:34:56');
$event->event_date = new DateTimeImmutable('2026-06-24');
$event->save();

$fresh->started_at instanceof DateTimeImmutable; // true
```

При чтении создается `DateTimeImmutable`. При записи `DateTimeInterface` форматируется в строку MySQL.

## Backed enum

```php
enum ProductState: string
{
    case Draft = 'draft';
    case Published = 'published';
}

class Product extends ActiveRecord
{
    protected static array $casts = [
        'state' => ProductState::class,
    ];
}

$product->state = ProductState::Published;
$product->save();

Product::findOrFail($product->id)->state === ProductState::Published;
```

Если в БД лежит значение, которого нет в enum, при гидратации будет `ValueError`.

## Пользовательский caster

```php
final class PrefixCaster
{
    public function get(mixed $value, string $field, string $modelClass): string
    {
        return 'read:' . $value;
    }

    public function set(mixed $value, string $field, string $modelClass): string
    {
        return 'stored:' . $value;
    }
}

class Product extends ActiveRecord
{
    protected static array $casts = [
        'external_code' => new PrefixCaster(),
    ];
}
```

На чтении вызывается `get()`, на записи в БД - `set()`. Если в caster-е не нужен `$field` или `$modelClass`, можно их не использовать.

## Accessors

Accessor делает виртуальное или переопределенное свойство:

```php
class Product extends ActiveRecord
{
    protected function getDisplayTitle(): string
    {
        return '#' . $this->id . ' ' . strtoupper((string) $this->getRaw('title'));
    }
}

echo Product::find(1)->display_title;
```

Имена переводятся из snake_case в StudlyCase:

| Свойство | Метод |
| --- | --- |
| `display_title` | `getDisplayTitle()` |
| `seo_h1` | `getSeoH1()` |

Accessor может быть `protected`. Внутри accessor-а используйте `getRaw()`, если читаете то же поле и не хотите рекурсии.

Если имя accessor-а совпадает с колонкой, accessor влияет на обычное чтение:

```php
protected function getTitle(): string
{
    return trim((string) $this->getRaw('title'));
}
```

## Mutators

Mutator вызывается при присваивании свойства:

```php
class Product extends ActiveRecord
{
    protected function setTitle($value): string
    {
        return trim((string) $value);
    }
}

$product->title = '  Phone  ';
```

Если mutator возвращает значение, оно записывается в состояние модели. Если mutator возвращает `null`, ActiveRecord считает, что mutator сам обработал присваивание:

```php
protected function setSlug($value): null
{
    $this->setRaw('slug', strtolower((string) $value));
    return null;
}
```

## Специальные значения при присваивании

```php
$product->published_at = '';       // null, потому что поле заканчивается на _at
$product->deleted_at = SQL_NULL;   // null
```

Если присвоить ActiveRecord-модель relation-свойству, запишется `{relation}_id`:

```php
$product->category = $category; // category_id = $category->id
$product->category = null;      // category_id = null
```

## `$appends` и accessors

Accessor доступен всегда:

```php
echo $product->display_title;
```

`$appends` нужен только для сериализации:

```php
class Product extends ActiveRecord
{
    protected static array $appends = ['display_title'];

    protected function getDisplayTitle(): string
    {
        return strtoupper((string) $this->getRaw('title'));
    }
}

$product->toArray(); // содержит display_title
```

## `_as_` formatters

Свойство вида `{field}_as_{formatter}` применяет formatter к значению поля, accessor-а или связи:

```php
echo $product->title_as_badge;
echo $product->category_as_model_title;
```

Для `title_as_badge` ActiveRecord:

1. читает `$product->title`;
2. ищет функцию `as_badge($value, $field, $object)`;
3. если функции нет, ищет класс `As_badge` с public static `call($value, $field, $object)`;
4. возвращает результат formatter-а.

Глобальная функция имеет приоритет над классом.

Пример функции:

```php
function as_badge($value, $field, $object): string
{
    return '[' . $field . ':' . strtoupper((string) $value) . ':' . $object->id . ']';
}
```

Пример класса:

```php
class As_model_title
{
    public static function call($value, $field, $object): ?string
    {
        return $value?->title;
    }
}
```

`_as_` хорош для переиспользуемого форматирования между моделями: одна функция или класс может обслуживать разные поля и разные ActiveRecord-объекты.

Если formatter не найден или `call()` не public static, будет `BadMethodCallException` с подсказкой.
