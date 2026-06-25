# Связи И Pivot

ActiveRecord строит почти все связи из имен таблиц и колонок. Пока схема следует соглашениям, модель не требует методов `category()`, `products()` и похожего бойлерплейта.

## Прямой belongs-to

Если в таблице `products` есть колонка `category_id`, то у `Product` появляется связь `category`:

```php
class Product extends ActiveRecord {}
class Category extends ActiveRecord {}

$product = Product::find(1);

echo $product->category->title;
```

Правило:

- имя связи: единственное число целевой таблицы (`category`);
- колонка на исходной таблице: `{relation}_id` (`category_id`);
- целевая таблица: plural от имени связи (`categories`);
- целевой класс должен существовать (`Category extends ActiveRecord`).

Если relation class не найден или по колонкам связь вывести нельзя, свойство вернет `null`. Если связь есть, но foreign key пустой, вернется пустой ActiveRecord-набор: чтение его полей даст `null`, `isEmpty()` будет `true`.

## Прямой has-many

Если в `products` есть `category_id`, то у `Category` появляется `products`:

```php
$category = Category::find(1);

foreach ($category->products as $product) {
    echo $product->title;
}
```

Правило обратной связи:

- имя связи: plural целевой таблицы (`products`);
- в целевой таблице есть колонка `{source_singular}_id` (`category_id`);
- целевой класс существует (`Product`).

## Коллекционный контекст экономит запросы

При обходе набора belongs-to грузится пачкой:

```php
foreach (Product::orderBy('id') as $product) {
    echo $product->category?->title;
}
```

Для всех продуктов из одного набора ActiveRecord соберет `category_id` и загрузит категории одним запросом, а не отдельным запросом на каждую строку.

## Underscore-переход

Имя связи с `_` в начале - compatibility-синтаксис явного перехода:

```php
$category = Product::find(1)->_categories;
```

Для прямой belongs-to это полезно, когда хочется писать plural-имя таблицы, но колонка остается singular (`category_id`). То есть `_categories` у продукта найдет `products.category_id`.

Это не магическое угадывание many-to-many. Это обычный переход к конкретной таблице по реальной колонке.

## Pivot-переход выполняется явно

Автоматические связи используют только прямые foreign-key колонки. Pivot-таблица сама по себе не угадывается:

```php
Category::find(1)->to_products; // null
Product::find(1)->categories;   // null, если нет прямого products.category_id для categories
```

Чтобы пройти через pivot, объявите модель промежуточной таблицы и явно пройдите через нее:

```php
class Product extends ActiveRecord {}
class Category extends ActiveRecord {}
class Categories_to_product extends ActiveRecord {}

$products = Category::find(1)
    ->_categories_to_products
    ->_products;
```

В тестовой схеме это читает так:

1. `Category::find(1)` - одна категория.
2. `->_categories_to_products` - строки pivot-таблицы, где `category_id = 1`.
3. `->_products` - продукты из этих pivot-строк по `product_id`.

Обратный путь:

```php
$categories = Product::find(2)
    ->_categories_to_products
    ->_categories;
```

Пример длинной цепочки:

```php
$brands = Category::find(1)
    ->_categories_to_products
    ->_products
    ->_brands;
```

Здесь `_brands` в конце уже не pivot, а прямой belongs-to из `products.brand_id` в `brands.id`.

Еще один вид цепочки:

```php
$brands = Product::all()
    ->_categories
    ->_brands;
```

Такой код будет работать в схеме, где `products.category_id` ведет в `categories`, а у `categories` есть `brand_id`. В тестовой схеме бренд находится на самом продукте, поэтому реальный путь к брендам из категории идет через pivot и продукты:

```php
$brands = Category::find(1)
    ->_categories_to_products
    ->_products
    ->_brands;
```

## `linked()` и `allLinked()`

`linked($table)` - программный alias одного underscore-перехода:

```php
$pivotRows = $category->linked('categories_to_products');
$products = $pivotRows->linked('products');
```

Это то же, что:

```php
$pivotRows = $category->_categories_to_products;
$products = $pivotRows->_products;
```

`allLinked($relation)` повторяет переход до 100 раз, собирает id всех встреченных строк и возвращает уникальный набор этой модели. Это compatibility-инструмент для иерархий и старого кода; в новом коде обычно понятнее писать явную цепочку.

## Явный belongs-to и has-many

Когда соглашения не подходят, связь можно объявить вручную:

```php
class Article extends ActiveRecord
{
    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class, 'article_id');
    }
}
```

Свойство вызывает `get()` автоматически:

```php
echo Article::find(1)->author->name;

foreach (Article::find(1)->comments as $comment) {
    echo $comment->text;
}
```

Метод возвращает `RelationDefinition`, поэтому через метод доступны операции связи:

```php
$article = Article::find(1);

$article->author()->associate($user);
$article->save();

$article->author()->dissociate();
$article->save();
```

## Явный many-to-many

Для чтения pivot можно использовать underscore-переход. Для записи pivot нужна явно объявленная `belongsToMany()`:

```php
class Product extends ActiveRecord
{
    public function pivotCategories()
    {
        return $this->belongsToMany(
            Category::class,
            'categories_to_products',
        );
    }
}
```

Чтение:

```php
$categories = Product::find(2)->pivotCategories;
```

Запись:

```php
$product = Product::findOrFail(2);

$product->pivotCategories()->attach(5);
$product->pivotCategories()->attach([5, 7]);
$product->pivotCategories()->attach(5, ['sort' => 10]);

$product->pivotCategories()->detach(5);
$product->pivotCategories()->detach([5, 7]);
$product->pivotCategories()->detach(); // удалить все pivot-строки этого owner

$product->pivotCategories()->sync([2, 5, 7]);
```

`sync()` выполняется в транзакции: если вставка нового набора падает, старый pivot-набор откатывается.

Если pivot-таблицу не передать, имя строится как `{min_table}_to_{max_table}`:

```php
return $this->belongsToMany(Category::class);
// categories_to_products
```

Но автоматического выбора между несколькими pivot-таблицами нет. Если в проекте есть несколько вариантов связи, задайте таблицу явно.

## Relation manager и relation definition

Когда связь найдена автоматически, вызов метода возвращает `RelationManager`:

```php
$product = Product::find(1);

$category = $product->category()->get();

$product->category()->associate($category);
$product->category()->dissociate();
```

`RelationManager` намеренно отказывается от pivot-записи:

```php
$product->category()->attach(2); // LogicException
```

Причина простая: pivot-таблицы не угадываются. `attach()`, `detach()` и `sync()` доступны только у `RelationDefinition`, который вернул явно объявленный `belongsToMany()`.

## Eager loading

```php
$products = Product::with('category')
    ->where('is_active', true);

$products = Product::with('category.parent')
    ->limit(100);
```

`with()` добавляет список связей, а при первом выполнении запроса ActiveRecord загрузит весь набор и указанную связь. Это не меняет SQL основного запроса через join; связи кладутся в relation cache строк.

Сериализация включает уже загруженные связи:

```php
$array = Product::with('category')->findOrFail(1)->toArray();
```

Если связь не загружена, `toArray()` сама ее не догружает.

## `has()` и `whereHas()`

```php
Product::has('category');
Product::doesntHave('category');

Product::whereHas('category', function ($category) {
    $category->where('id', 2);
});

Product::whereDoesntHave('category', function ($category) {
    $category->where('is_hidden', true);
});
```

Эти методы используют `EXISTS`. Сейчас они рассчитаны на прямые belongs-to и has-many связи, которые можно вывести по колонкам. Для many-to-many используйте явный запрос через pivot-модель или explicit `belongsToMany()` и обычные условия.

## Связи при присваивании

Для прямой belongs-to можно присвоить модель:

```php
$product = Product::findOrFail(1);
$category = Category::findOrFail(2);

$product->category = $category;
$product->save();
```

Это запишет `category_id`.

Присвоение `null` отвязывает:

```php
$product->category = null;
$product->save();
```

Для explicit relation можно делать то же через `associate()`/`dissociate()`, что обычно читается яснее.
