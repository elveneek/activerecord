# Коллекции, Состояние И JSON

## `foreach`, индексы и count

ActiveRecord реализует `IteratorAggregate`, `ArrayAccess`, `Countable` и `JsonSerializable`. Поэтому работают `foreach`, `$products[0]`, `isset`, `empty` и `count()`.

```php
$products = Product::where('category_id', 5)->orderBy('id');

foreach ($products as $index => $product) {
    echo $index;
    echo $product->title;
}

$first = $products[0];
$count = count($products);            // PHP-функция, через Countable
$count = $products->count();          // метод — то же самое
```

Числовой индекс работает с коллекцией. Строковый индекс работает с текущей строкой:

```php
$product = Product::find(1);

echo $product['title'];

$product['title'] = 'New title';
unset($product['title']);
```

Несуществующий числовой индекс возвращает `null`. `isset($products[999])` будет `false`, `empty($products[999])` будет `true`.

Повторные `foreach` работают с начала. После полного `foreach` ручной iterator стоит в конце; можно вызвать `rewind()`.

## Сетки: `slice()`

Для вывода коллекции «рядами» по N штук (карточки, галерея, таблица) есть `slice()`. Он нарезает результат на под-коллекции, каждая из которых — полноценный итерируемый/countable ActiveRecord-набор:

```php
foreach (Product::all()->orderBy('sort')->slice(3) as $row) {
    echo '<div class="row">';
    foreach ($row as $product) {
        echo '<div class="col">' . $product->title . '</div>';
    }
    echo '</div>';
}
```

`slice(3)` на 5 записях даст 2 ряда: первый с тремя, второй с двумя (остаток). Каждый ряд поддерживает `count($row)`, `$row[0]`, повторный `foreach`. Размер по умолчанию — `2`. Размер `< 1` бросает `InvalidArgumentException`.

`slice()` материализует весь набор (это шаблонный хелпер для ограниченных выборок); для обработки больших таблиц без загрузки всего используйте `chunkById()`.

Ручные методы iterator-а:

```php
$products->rewind();
$products->current();
$products->next();
$products->key();
$products->valid();
$products->seek(10);
```

`seek($position)` переставляет ручной индекс коллекции и возвращает тот же объект.

## Row-bound объекты

`$products[0]`, `$products->first()` и элементы `foreach` - это row-bound модели. Они привязаны к конкретной строке, не зависят от движения курсора исходной коллекции и безопасны для изменения.

```php
$products = Product::whereIn('id', [1, 2])->orderBy('id');

$first = $products[0];
$second = $products[1];

$first->title = 'One';
$second->title = 'Two';

$first->saveCurrent(); // сохраняет только первую строку
$products->saveAll();  // сохраняет все dirty строки набора
```

## Поиск внутри загруженного набора

```php
$products = Product::all()->orderBy('rand()');

$product = $products->by_id(3);
```

`by_id()` ищет строку в текущем наборе по primary key, возвращает row-bound модель или `null` и переставляет ручной указатель коллекции на найденную строку.

## Объединение наборов

```php
$products = Product::where('category_id', 1)
    ->plus(Product::where('category_id', 2));

$products = Product::where('category_id', 1)
    ->plus([5, 7, 9]);
```

`plus()` собирает primary key текущего набора, добавляет id из массива, одной записи или другого ActiveRecord-набора и возвращает `findMany()` по уникальным id.

## Сериализация

```php
$array = Product::findOrFail(1)->toArray();
$rows = Product::where('category_id', 5)->toArray();

$json = Product::where('category_id', 5)->toJson(JSON_PRETTY_PRINT);
$json = json_encode(Product::where('category_id', 5), JSON_THROW_ON_ERROR);
```

Если объект привязан к одной строке, `toArray()` возвращает массив одной строки. Если это набор, возвращается список массивов.

`to_json` и `to_array` доступны как compatibility-свойства:

```php
Product::select('id', 'title')->to_json;
Product::select('id', 'title')->to_array;
```

В новом коде лучше методы `toJson()` и `toArray()`.

## JSON по id

```php
$json = Product::where('category_id', 5)
    ->to_json_by_id(JSON_PRETTY_PRINT);
```

Результат - JSON-объект, где ключи равны primary key строк.

## `$hidden`

`$hidden` скрывает поля из `toArray()`, `toJson()` и `json_encode()`:

```php
class User extends ActiveRecord
{
    protected static array $hidden = ['password_hash', 'internal_token'];
}
```

Поля остаются загруженными и доступны напрямую:

```php
echo $user->password_hash; // доступно

$user->toArray(); // password_hash нет
```

`$hidden` не влияет на SQL `SELECT`, не защищает чтение и не запрещает сохранение. Это только фильтр выдачи.

Фильтр применяется к обычным атрибутам, aliases/extras из запроса, `$appends` и уже загруженным связям.

## `$visible`

Непустой `$visible` превращает сериализацию в whitelist и имеет приоритет над `$hidden`:

```php
class Product extends ActiveRecord
{
    protected static array $hidden = ['id'];
    protected static array $visible = ['id', 'display_title'];
    protected static array $appends = ['display_title'];

    protected function getDisplayTitle(): string
    {
        return strtoupper((string) $this->getRaw('title'));
    }
}
```

В массив попадут только `id` и `display_title`.

Если вычисляемое поле должно быть видно при `$visible`, его нужно указать и в `$appends`, и в `$visible`.

Пустой `$visible = []` означает, что whitelist отключен.

## `$appends`

`$appends` добавляет accessor-поля в сериализацию:

```php
class Product extends ActiveRecord
{
    protected static array $appends = ['display_title'];

    protected function getDisplayTitle(): string
    {
        return '#' . $this->id . ' ' . strtoupper((string) $this->getRaw('title'));
    }
}

$product = Product::findOrFail(1);

echo $product->display_title; // accessor доступен и без $appends

$product->toArray(); // содержит display_title
```

`$appends` не создает колонку, не добавляет поле в SQL, не делает модель dirty и не сохраняет вычисленное значение.

Accessor вызывается для каждой сериализуемой строки. Не делайте внутри него отдельные запросы без необходимости: легко получить N+1.

## Связи в JSON

Сериализация включает только уже загруженные связи:

```php
$product = Product::with('category')->findOrFail(1);

$array = $product->toArray();
// category будет внутри
```

Если связь не загружена, `toArray()` не пойдет за ней сама.

Есть защита от циклической сериализации: если строка уже находится в стеке сериализации, она сворачивается до primary key.

## Extras из SELECT

Колонки и aliases, которых нет в схеме таблицы, попадают в `extras` конкретного row view:

```php
$product = Product::join('category')
    ->select('products.*')
    ->addSelect('category.title AS category_title')
    ->where('products.id', 1)
    ->firstOrFail();

echo $product->category_title;
```

Extras доступны как обычные свойства и попадают в сериализацию, если не скрыты `$hidden` или не отфильтрованы `$visible`.

## Частичные выборки

```php
$product = Product::select('id', 'title')->find(1);

echo $product->title;    // есть
echo $product->brand_id; // null по умолчанию
```

По умолчанию существующее, но не выбранное поле возвращает `null`. В strict mode будет `MissingAttributeException`:

```php
Product::strictMode(true);

Product::select('id')->find(1)->title; // MissingAttributeException
```

Identity map помнит, какие колонки загружены. Если сначала загрузить частичную строку, а потом полный `find()`, недостающие колонки будут дочитаны отдельным запросом.

## Dirty tracking

```php
$product = Product::findOrFail(1);

$product->title = 'New title';

$product->isNew();           // новая строка или уже persisted
$product->isDirty();          // true
$product->isDirty('title');   // true
$product->dirtyAttributes();  // ['title' => 'New title']
$product->original('title');  // старое значение

$product->save();

$product->wasChanged();       // true, если что-то сохранялось
$product->wasChanged('title');
```

Если нужно откатить in-memory изменения:

```php
$product->discardChanges();
```

`discardChanges()` возвращает атрибуты к `original` и очищает dirty/wasChanged.

## Raw-доступ

```php
$raw = $product->getRaw('title');

$product->setRaw('title', 'Value');
```

`getRaw()` читает значение из состояния строки без accessor-а. `setRaw()` записывает значение в состояние и помечает поле dirty.

## Refresh и reload

```php
$product->refresh();
$product->reload();
```

`refresh()` перечитывает строку по primary key без кэша. Если строка dirty, нужен `force: true`:

```php
$product->refresh(force: true);
```

Иначе будет `LogicException`, чтобы случайно не потерять несохраненные изменения.

`reload($field = null, $force = false)` сейчас является alias `refresh($force)`. Аргумент `$field` оставлен для compatibility и не ограничивает перечитывание одним полем.

## Tree

```php
$tree = Category::all()->orderBy('sort')->tree();
$subtree = Category::all()->orderBy('sort')->tree($rootId);
```

`tree()` строит массив моделей по parent-колонке `{singular_table}_id`. Для `categories` это `category_id`. Дети кладутся в публичное свойство `queryTree` каждой модели.

`tree(false)` считает корнем `NULL`. Можно передать id корня или модель.
