# Методы Модели Вместо Scopes

В ActiveRecord нет отдельной системы scopes, префиксов `scope*`, специальных аргументов `$query` и магии имен. Она не нужна: обычные методы модели уже умеют начинать и продолжать запрос.

Это важная часть философии библиотеки: минимум правил, максимум обычного PHP.

## Статический метод как именованный конструктор запроса

```php
class Product extends ActiveRecord
{
    public static function published(): static
    {
        return static::where('is_published', true);
    }
}

$products = Product::published()
    ->orderBy('created_at', 'desc');
```

Статический метод удобен, когда цепочка начинается от класса.

Используйте `static::`, а не `self::`, чтобы наследование продолжало работать.

## Public instance-метод как продолжение запроса

```php
class Product extends ActiveRecord
{
    public function deleted(): static
    {
        return $this->where('is_deleted', true);
    }
}

$products = Product::where('category_id', 5)
    ->deleted()
    ->orderBy('id');
```

Instance-метод получает текущий ActiveRecord-объект через `$this`, поэтому сохраняет все уже добавленные условия, joins, сортировки, eager loads и настройки кэша.

## Protected instance-метод тоже работает

```php
class Product extends ActiveRecord
{
    protected function expensive(int $from): static
    {
        return $this->where('price', '>=', $from);
    }
}

$products = Product::where('is_active', true)
    ->expensive(1000);
```

`__call()` умеет вызвать `protected` методы модели, если они объявлены в наследнике `ActiveRecord`. `private` методы не вызываются.

Это удобно для методов, которые должны быть доступны в fluent-цепочке, но не хочется делать их частью явного публичного API класса.

## Полный пример

```php
class Product extends ActiveRecord
{
    public static function published(): static
    {
        return static::where('is_published', true);
    }

    public function deleted(): static
    {
        return $this->where('is_deleted', true);
    }

    protected function expensive(int $from): static
    {
        return $this->where('price', '>=', $from);
    }
}

$products = Product::where('is_active', true)
    ->expensive(1000);
```

Никакого `scopeExpensive()`, никакого `$query` в аргументах. Текущий запрос уже находится в `$this`.

## Что возвращать из метода

Лучший стиль - явно возвращать ActiveRecord-цепочку:

```php
public function active(): static
{
    return $this->where('is_active', true);
}
```

Если метод вернет `null`, `__call()` вернет текущий объект, чтобы цепочка не сломалась. Но для читаемости лучше возвращать результат явно.

```php
public function onlyAvailable(): static
{
    $this->where('stock', '>', 0);

    return $this;
}
```

## Методы могут комбинировать любые части запроса

```php
class Product extends ActiveRecord
{
    public function forCatalog(): static
    {
        return $this
            ->where('is_active', true)
            ->whereNotNull('title')
            ->orderBy('sort');
    }

    public function inPriceRange(int $from, int $to): static
    {
        return $this->whereBetween('price', [$from, $to]);
    }

    public function withPublicRelations(): static
    {
        return $this->with('category', 'brand');
    }
}

$products = Product::forCatalog()
    ->inPriceRange(1000, 5000)
    ->withPublicRelations();
```

Метод может добавлять `where`, `join`, `select`, `with`, `remember`, `limit`, `orderBy` - все, что доступно обычной цепочке.

## Не конфликтуйте с именами API

Если имя метода совпадает с built-in query method, сначала сработает встроенный forwarding:

```php
where
select
join
orderBy
with
remember
```

Не называйте пользовательские методы так же. Лучше доменные имена:

```php
published()
available()
forCatalog()
expensive()
onlyDiscounted()
```

## Переиспользование между моделями

Для query-фрагментов используйте обычный PHP:

```php
trait HasPublicationFilters
{
    public static function published(): static
    {
        return static::where('is_published', true);
    }

    protected function recent(): static
    {
        return $this->orderBy('created_at', 'desc');
    }
}

class Product extends ActiveRecord
{
    use HasPublicationFilters;
}

class Article extends ActiveRecord
{
    use HasPublicationFilters;
}
```

Для переиспользуемого форматирования между классами используйте `_as_` formatters:

```php
function as_badge($value, $field, $object): string
{
    return '[' . $field . ':' . strtoupper((string) $value) . ']';
}

echo Product::find(1)->title_as_badge;
echo Category::find(1)->title_as_badge;
```

Это не фильтр запроса, а общий слой представления: один formatter можно применять к разным моделям, полям, accessors и связям.

## Когда метод должен быть статическим, а когда instance

Статический:

```php
Product::published();
```

Хорошо подходит для начала запроса.

Instance:

```php
Product::where('category_id', 5)->publishedInCatalog();
```

Хорошо подходит для продолжения уже собранной цепочки.

Protected instance:

```php
Product::where('is_active', true)->expensive(1000);
```

Хорошо подходит для fluent-методов, которые не хочется показывать как обычный публичный метод объекта.

## Методы и материализованные строки

Если вызвать метод на row-bound объекте, изменение запроса создаст новый объект запроса, а не будет менять привязанную строку:

```php
$product = Product::findOrFail(1);

$otherQuery = $product->where('category_id', 5);
```

Это защищает конкретную строку от превращения в новый набор.

Если коллекция уже загружена и содержит dirty-строки, менять ее запрос нельзя: будет `DirtyResultCannotBeRequeriedException`. Сначала сохраните или откатите изменения.
