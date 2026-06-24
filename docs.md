# Elveneek ActiveRecord — документация публичного API

## 1. Основная идея

Elveneek ActiveRecord использует один публичный класс модели одновременно как:

- ленивый запрос;
- набор строк;
- одну строку;
- изменяемую Active Record-модель.

```php
class Product extends \Elveneek\ActiveRecord {}

$products = Product::where('price', '>', 100)
    ->orderBy('price', 'desc')
    ->limit(10);

// SQL выполняется только здесь — при чтении результата.
foreach ($products as $product) {
    echo $product->title;
}
```

Запрос остаётся ленивым до чтения поля, `foreach`, доступа по индексу или терминального метода (`first()`, `count()`, `toArray()` и т. п.). Элемент, полученный из `foreach` или по индексу, привязан к конкретной строке и не зависит от движения курсора исходного набора.

## 2. Подключение и модели

### Подключение по переменным окружения

```php
\Elveneek\ActiveRecord::$db = \Elveneek\ActiveRecord::connect();
```

Используются переменные `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`. `DB_AUTO_RECONNECT=1` включает `PDOProxy`; автоматически повторяются только безопасные операции чтения, но не записи с неизвестным результатом.

Подключение можно зарегистрировать через `DB`:

```php
\Elveneek\DB::setConnection($pdo);
$pdo = \Elveneek\DB::connection();
```

### Объявление модели

```php
class Product extends \Elveneek\ActiveRecord {}
```

По умолчанию `Product` соответствует таблице `products`, `Category` — `categories`, `Person` — `people`. Для namespaced-классов используется короткое имя класса.

Настройки модели задаются защищёнными статическими свойствами:

```php
class Product extends \Elveneek\ActiveRecord
{
    protected static string $table = 'catalog_products';
    protected static string $primaryKey = 'product_id';
    protected static string $defaultOrder = 'sort';
    protected static ?string $versionColumn = 'lock_version';

    protected static array $casts = [
        'product_id' => 'int',
        'price' => 'decimal:2',
        'is_active' => 'bool',
        'settings' => 'json',
        'published_at' => 'datetime',
    ];

    protected static array $fillable = ['title', 'price'];
    protected static array $hidden = ['password_hash'];
    protected static array $visible = [];
    protected static array $appends = ['display_title'];
}
```

Поддерживаемые casts: `int`, `float`, `decimal:N`, `bool`, `string`, `json`, `array`, `datetime`, `date`, backed enum и пользовательский caster с методами `get()`/`set()`.

Без объявления `$casts` действуют соглашения: первичный ключ и поля `*_id` преобразуются в `int`, поля `is_*` — в `bool`. Поля `*_at` автоматически не преобразуются и остаются строками. Явно заданный `$casts` всегда имеет приоритет над соглашениями.

### `$fillable`: разрешённые поля для `fill()`

`$fillable` защищает массовое присваивание — ситуацию, когда массив входных данных целиком передаётся модели:

```php
class Product extends \Elveneek\ActiveRecord
{
    protected static array $fillable = ['title', 'price'];
}

$product = Product::findOrFail(1);
$product->fill([
    'title' => 'Новое название', // будет присвоено
    'price' => 1500,             // будет присвоено
    'is_admin' => true,          // будет проигнорировано
]);
```

Правила `fill()`:

- без второго аргумента разрешены только имена из `$fillable`;
- запрещённые поля молча игнорируются;
- `$fillable = []` запрещает массовое присваивание всех полей;
- если свойство `$fillable` не объявлено и список `only` не передан, выбрасывается `MassAssignmentException`;
- аргумент `only` задаёт отдельный список для конкретного вызова и заменяет `$fillable`:

```php
$product->fill($input, only: ['title', 'price']);
```

`forceFill()` обходит защиту и поэтому подходит только для доверенных данных:

```php
$product->forceFill([
    'title' => 'Системное название',
    'is_admin' => true,
]);
```

`$fillable` применяется только методом `fill()`. Прямое присваивание, `setRaw()`, `forceFill()`, `create([...])` и `insert([...])` им не фильтруются. Поэтому нельзя передавать необработанный HTTP request в `create()` или `insert()`:

```php
// Безопасный вариант для пользовательского ввода:
$product = Product::create()->fill($request)->save();

// Допустимо только для заранее сформированного доверенного массива:
$product = Product::insert($trustedAttributes);
```

### `$hidden`: исключение полей из массива и JSON

`$hidden` — чёрный список сериализации:

```php
protected static array $hidden = ['password_hash', 'internal_token'];
```

Эти поля не попадут в `toArray()`, `toJson()`, `json_encode($model)` и результат сериализации коллекции. При этом они остаются загруженными, доступны как `$user->password_hash`, могут изменяться и сохраняться. `$hidden` не является защитой чтения и не влияет на SQL `SELECT`.

Фильтр применяется к обычным атрибутам, алиасам из запроса, вычисляемым `$appends` и явно загруженным связям. Поэтому именем в `$hidden` можно скрыть также accessor или загруженную связь.

### `$visible`: строгий список сериализуемых полей

Непустой `$visible` превращается в белый список и имеет приоритет над `$hidden`:

```php
protected static array $visible = ['id', 'title', 'display_title'];
```

В массив или JSON попадут только перечисленные имена. Это относится также к полям из `$appends`, алиасам и загруженным связям. Если вычисляемое поле должно присутствовать при непустом `$visible`, его нужно включить в оба списка:

```php
protected static array $visible = ['id', 'title', 'display_title'];
protected static array $appends = ['display_title'];
```

При `$visible = []` белый список отключён, и сериализация использует все доступные поля за вычетом `$hidden`. Как и `$hidden`, это исключительно формат выдачи: прямой доступ к свойствам модели не ограничивается.

### `$appends`: вычисляемые поля в массиве и JSON

`$appends` перечисляет виртуальные поля, которые отсутствуют в таблице, но должны вычисляться и добавляться при сериализации. Каждому имени нужен accessor. Для snake_case-имени `display_title` используется метод `getDisplayTitle()`:

```php
class Product extends \Elveneek\ActiveRecord
{
    protected static array $hidden = ['internal_code'];
    protected static array $appends = ['display_title'];

    protected function getDisplayTitle(): string
    {
        return '#' . $this->id . ' — ' . mb_strtoupper((string) $this->getRaw('title'));
    }
}

$product = Product::findOrFail(15);

$product->display_title; // accessor доступен как обычное свойство
$product->toArray();     // содержит ключ display_title
$product->toJson();      // содержит поле display_title
```

Пример результата:

```json
{
    "id": 15,
    "title": "Телефон",
    "display_title": "#15 — ТЕЛЕФОН"
}
```

`$appends` не создаёт колонку, не добавляет поле в SQL, не делает модель dirty и не сохраняет вычисленное значение. Он только включает результат accessor-а в сериализацию. Сам accessor доступен через `$product->display_title` и без `$appends`; список нужен именно для `toArray()`/JSON.

Сначала собираются обычные атрибуты, вычисляемые `$appends` и уже загруженные связи, после чего к общему результату применяется `$visible` либо `$hidden`. Accessor вызывается для каждой сериализуемой строки, поэтому внутри него лучше не выполнять отдельные запросы — иначе легко получить N+1.

## 3. Создание запроса

### `all()`

Создаёт ленивый запрос всей таблицы. Данные немедленно не загружаются.

```php
$products = Product::all();
```

### `find($id)`

Ленивый поиск по primary key. Метод работает и после уже построенного запроса, сохраняя его ограничения.

```php
$product = Product::find(15);
$product = Product::select('id', 'title')->find(15);
$product = Product::where('site_id', 2)->find(15);
```

Если строка отсутствует, возвращается пустой ActiveRecord-набор.

### `findMany(array $ids)`

Возвращает строки по списку primary key в заданном порядке. Уже известные identity map строки не запрашиваются повторно.

```php
$products = Product::findMany([5, 2, 9]);
```

### `findOrNull($id)` и `findOrFail($id)`

Немедленные варианты поиска:

```php
$product = Product::findOrNull(15); // Product|null
$product = Product::findOrFail(15); // Product или ModelNotFoundException
```

### `findOne($id)`

Instance-вариант поиска, продолжающий текущий запрос.

```php
$product = Product::where('site_id', 2)->findOne(15);
```

### `fromTable($table)`

Создаёт модель по имени таблицы. Соответствующий PHP-класс обязан существовать.

```php
$products = ActiveRecord::fromTable('products');
```

### `toQuery()` и `fromQuery()`

`toQuery()` возвращает immutable snapshot низкоуровневого Query Builder. `fromQuery()` создаёт модель из совместимого запроса основной таблицы.

```php
$builder = Product::where('is_active', true)->toQuery();
$expensive = $builder->where('price', '>', 1000);

$products = Product::fromQuery(
    DB::table('products')->where('is_active', true)
);
```

Агрегатный или несовместимый builder нельзя превратить в изменяемые модели: будет выброшен `IncompatibleQueryException`.

### `copy()` и `resetQuery()`

```php
$base = Product::where('is_active', true);
$branch = $base->copy()->where('price', '>', 1000);
$fresh = $base->resetQuery();
```

Изменять уже материализованный запрос можно, только если в нём нет несохранённых изменений.

## 4. Условия WHERE

### `where()`

```php
Product::where('title', 'Телефон');
Product::where('price', '>=', 1000);
Product::where(['is_active' => true, 'type' => 2]);
Product::where('price > ? AND stock > ?', 1000, 0); // legacy/raw-форма с bindings
```

`null` преобразуется в `IS NULL`/`IS NOT NULL`:

```php
Product::where('deleted_at', null);
Product::where('deleted_at', '!=', null);
```

Поддерживаемые операторы: `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `LIKE`, `NOT LIKE`, `IS`, `IS NOT`.

### `orWhere()`

Добавляет условие через `OR`:

```php
Product::where('is_active', true)->orWhere('is_featured', true);
```

### Списки, NULL, диапазоны и LIKE

```php
Product::whereIn('id', [1, 2, 3]);
Product::orWhereIn('id', [4, 5]);
Product::whereNotIn('id', [6, 7]);
Product::orWhereNotIn('id', [8, 9]);

Product::whereNull('deleted_at');
Product::orWhereNull('deleted_at');
Product::whereNotNull('published_at');
Product::orWhereNotNull('published_at');

Product::whereBetween('price', [100, 500]);
Product::whereNotBetween('price', [100, 500]);
Product::whereLike('title', '%phone%');
Product::orWhereLike('text', '%phone%');
```

`whereIn([], ...)` создаёт заведомо ложное условие; `whereNotIn([], ...)` — заведомо истинное.

### Группы условий

Callback получает изменяемый фасад над immutable builder, поэтому явный `return` не обязателен:

```php
Product::where('is_active', true)
    ->whereGroup(function ($query) {
        $query->whereLike('title', '%phone%')
            ->orWhereLike('text', '%phone%');
    });

Product::orWhereGroup(fn ($query) =>
    $query->where('type', 1)->where('stock', '>', 0)
);
```

### Raw-условия

```php
Product::whereRaw('MATCH(title, text) AGAINST (?)', $term);
Product::orWhereRaw('JSON_CONTAINS(tags, ?)', json_encode($tag));
```

Bindings передаются отдельно и не подставляются строковой заменой.

### Условная текучесть

```php
$products = Product::all()
    ->when($categoryId, fn ($q, $id) => $q->where('category_id', $id))
    ->unless($showHidden, fn ($q) => $q->where('is_hidden', false))
    ->tap(fn ($q) => logger($q->toSql()));
```

- `when($value, $callback, $default = null)` выполняет callback для truthy-значения;
- `unless(...)` — для falsy-значения;
- `tap($callback)` выполняет callback и возвращает исходный ActiveRecord.

### `search()`

Строит OR-группу `LIKE` по одному или нескольким полям:

```php
Product::search(['title', 'text'], 'phone');
```

## 5. SELECT, DISTINCT, сортировка и группировка

### `select()`, `addSelect()`, `selectRaw()`

```php
Product::select('id', 'title');
Product::select(['id', 'title']);
Product::select('products.*')->addSelect('category.title AS category_title');
Product::selectRaw('category_id, COUNT(*) AS total');
```

В strict mode обращение к существующему, но не выбранному полю вызывает `MissingAttributeException`.

### `distinct()`

```php
Product::distinct()->select('category_id');
```

### `orderBy()` и `orderByRaw()`

```php
Product::orderBy('price', 'desc')->orderBy('id');
Product::orderByRaw('FIELD(status, ?, ?)', ['new', 'old']);
```

Обычный `orderBy()` принимает только проверяемое имя поля и `asc`/`desc`.

### `groupBy()`, `groupByRaw()`, `having()`, `havingRaw()`

```php
Product::select('category_id')
    ->selectRaw('COUNT(*) AS total')
    ->groupBy('category_id')
    ->having('total', '>', 10)
    ->orderBy('total', 'desc');
```

### `limit()` и `offset()`

```php
Product::orderBy('id')->limit(20)->offset(40);
```

## 6. JOIN и подзапросы

### JOIN по явным колонкам

```php
Product::join('categories', 'categories.id', '=', 'products.category_id');
Product::leftJoin('prices', 'prices.product_id', '=', 'products.id');
Product::rightJoin('prices', 'prices.product_id', '=', 'products.id');
Product::crossJoin('currencies');
```

### JOIN по прямой связи

```php
Product::join('category');
Category::leftJoin('products');
```

JOIN по имени связи выводится только из прямого foreign key. Pivot-таблицы автоматически не используются.

### `joinSub()` и `leftJoinSub()`

```php
$prices = DB::table('prices')
    ->select('product_id')
    ->selectRaw('MAX(value) AS max_price')
    ->groupBy('product_id');

$products = Product::joinSub(
    $prices,
    'latest_prices',
    'latest_prices.product_id',
    '=',
    'products.id',
);
```

### `whereExists()`, `whereNotExists()`, `whereColumn()`

Эти методы доступны в публичном Query Builder и через ActiveRecord forwarding:

```php
$subquery = DB::table('prices')
    ->selectRaw('1')
    ->whereColumn('prices.product_id', '=', 'products.id');

Product::whereExists($subquery);
Product::whereNotExists($subquery);
```

## 7. Получение результата

### Одна строка и существование

```php
$first = $products->first();          // Product|null
$first = $products->firstOrFail();    // Product или исключение
$last = $products->last();

$products->exists();
$products->doesntExist();
$products->isEmpty();
$products->isNotEmpty();
```

`orStub()` превращает пустой результат в безопасный пустой ActiveRecord-запрос.

### Количество и агрегаты

```php
$products->count();
$products->sum('price');
$products->avg('price');
$products->min('price');
$products->max('price');
```

На незагруженном наборе `count()` выполняет отдельный SQL COUNT. `foundRows()` считает результат без `LIMIT/OFFSET`.

### Значения и списки

```php
$products->value('title');
$products->pluck('title');
$products->pluck('title', 'id');
```

### Явная загрузка и состояние загрузки

```php
$products->load();
$products->isLoaded();
$products->loadedCount();
$products->isFullyLoaded();
```

## 8. Коллекции, массивы и итерация

ActiveRecord реализует `IteratorAggregate`, `ArrayAccess`, `Countable` и `JsonSerializable`.

```php
foreach ($products as $index => $product) {}

$first = $products[0];
echo $first['title'];
$first['title'] = 'Новое название';

count($products);
isset($products[3]);
unset($products[3]);
```

Повторные и вложенные `foreach` независимы. `$products[0]` и `$products[1]` — разные row-bound объекты.

Для ручной навигации сохранены методы:

- `rewind()`;
- `current()`;
- `next()`;
- `key()`;
- `valid()`;
- `seek($position)`;
- `by_id($id)` — найти строку внутри уже полученного набора.

`plus($idsOrRecords)` объединяет текущий набор с ID, массивом ID или другим ActiveRecord-набором.

## 9. Атрибуты и состояние строки

### Чтение и запись

```php
echo $product->title;
$product->title = 'Новое название';

$raw = $product->getRaw('title');
$product->setRaw('title', $value);
$value = $product->get('title'); // compatibility-метод
```

### Dirty tracking

```php
$product->isNew();
$product->isDirty();
$product->isDirty('title');
$product->dirtyAttributes();
$product->original('title');
$product->wasChanged('title');
$product->discardChanges();
```

`refresh($force = false)` перечитывает строку из БД. Dirty-строку нельзя обновить без `force: true`. `reload()` — алиас с той же текущей семантикой.

### Accessors и mutators

```php
class Product extends ActiveRecord
{
    protected function getDisplayTitle()
    {
        return strtoupper($this->getRaw('title'));
    }

    protected function setTitle($value)
    {
        return trim($value);
    }
}

echo $product->display_title;
$product->title = '  Phone  ';
```

### Форматирование свойств через `_as_`

Виртуальное свойство `field_as_formatter` передаёт значение поля пользовательскому форматтеру. Имя разбирается по первому `_as_`: слева находится поле, связь или accessor, справа — имя форматтера.

```php
echo $product->is_deleted_as_admin_check_mark;
```

Для этого обращения ActiveRecord получает `$product->is_deleted`, а затем ищет форматтер двумя способами.

Первый способ — глобальная функция `as_*`:

```php
function as_admin_check_mark($value, $field = null, $object = null)
{
    return $value
        ? '<span class="yes">✔</span>'
        : '<span class="no">×</span>';
}
```

Она вызывается так:

```php
as_admin_check_mark(
    $product->is_deleted, // $value
    'is_deleted',         // $field
    $product,             // $object
);
```

Второй способ — сервис-класс `As_*` с публичным статическим методом `call()`:

```php
class As_model_title
{
    public static function call($value, $field, $object)
    {
        return $value->title;
    }
}

echo $product->category_as_model_title;
```

Для `category_as_model_title` значением `$value` будет `$product->category`, `$field` будет равен `category`, а `$object` — текущему объекту Product. Поэтому слева можно использовать не только колонку, но также автоматически или явно объявленную связь и accessor.

Порядок разрешения:

1. глобальная функция `as_formatter()`;
2. класс `As_formatter::call()`.

Если одновременно существуют функция и класс, используется функция. Метод класса обязан быть `public static`. Если подходящий форматтер отсутствует или `call()` объявлен неверно, выбрасывается `BadMethodCallException` с ожидаемыми именами функции и класса.

Настоящая колонка с `_as_` в имени имеет приоритет над магическим форматированием. Результат форматтера — только вычисляемое значение: он не становится dirty-атрибутом и не сохраняется в БД.

ActiveRecord не экранирует результат форматтера. Если форматтер возвращает HTML, ответственность за доверенность и экранирование входного значения лежит на прикладном коде. Форматтер связи может инициировать запрос; при выводе коллекции связь лучше загрузить заранее, чтобы избежать N+1.
### Mass assignment

```php
$product->fill($input, only: ['title', 'price']);
$product->forceFill($trustedData);
```

Без `only` метод `fill()` использует `$fillable` модели. `forceFill()` предназначен только для доверенных данных.

## 10. Сохранение

### `save()`, `saveCurrent()`, `saveAll()`

```php
$product->save();          // одна dirty/new строка
$product->saveCurrent();   // только строка текущего row view
$products->saveAll();      // все dirty/new строки набора в транзакции
```

`save()` выбрасывает `AmbiguousWriteException`, если изменено несколько строк. `saveAll()` сохраняет разные dirty-значения каждой строки и откатывает весь пакет при ошибке.

Диагностика последней операции:

```php
$products->affectedRows();
$products->lastSaveErrors();
```

### Новая строка

```php
$product = Product::create([
    'title' => 'Телефон',
    'price' => 1000,
])->save();

$product = Product::insert([
    'title' => 'Немедленная вставка',
]);
```

`create()` только создаёт new-state; INSERT выполняет `save()`. `insert()` сохраняет немедленно.

### Пакетная вставка

```php
$products = Product::create()
    ->addRow(['title' => 'Первый'])
    ->addRow(['title' => 'Второй'])
    ->saveAll();

Product::insertAll([
    ['title' => 'Первый'],
    ['title' => 'Второй'],
]);
```

### Bulk-команды без гидратации

```php
Product::whereNull('category_id')->updateAll(['is_orphan' => true]);
Product::where('stock', '<', 0)->deleteAll();
Product::where('category_id', 5)->increment('views');
Product::where('category_id', 5)->decrement('stock', 1);
```

Bulk-команды не создают объект на каждую строку.

### Удаление и truncate

```php
$product->delete();
$products->deleteAll();
Product::truncate(confirm: true);
```

`delete()` требует ровно одну строку. `truncate()` требует явное подтверждение.

### Составные операции

```php
$product = Product::firstOrCreate(
    ['sku' => $sku],
    ['title' => $title],
);

$product = Product::updateOrCreate(
    ['sku' => $sku],
    ['price' => $price],
);

Product::upsert(
    $rows,
    uniqueBy: ['sku'],
    update: ['title', 'price'],
);
```

`ioi()` возвращает `insert_id`, а если его нет — обычный primary key текущей строки.

### Optimistic lock

```php
protected static ?string $versionColumn = 'lock_version';
```

UPDATE проверяет исходную версию. При конкурентном изменении выбрасывается `StaleModelException`.

## 11. Связи

### Автоматические связи используют только прямые foreign-key колонки

Если в `products` есть `category_id`:

```php
$category = Product::find(1)->category; // belongs-to
$products = Category::find(1)->products; // has-many
```

Для автоматической связи учитываются имя свойства, имя таблицы и колонка `{name}_id`. Pivot-таблицы автоматически не инициируют many-to-many.

### Pivot-переход выполняется явно

Пусть существуют таблицы:

- `categories`;
- `products`;
- `categories_to_products` с `category_id` и `product_id`.

Тогда `$product->categories` **не** определяется автоматически. Для явного перехода через промежуточную модель используется старый синтаксис:

```php
$products = Category::find(1)
    ->_categories_to_products
    ->_products;
```

Для этого должны существовать классы моделей `Category`, `Categories_to_product` и `Product`.

`linked($table)` — программный compatibility-эквивалент одного `_relation`-перехода:

```php
$pivotRows = $category->linked('categories_to_products');
$products = $pivotRows->linked('products');
```

### Явное объявление many-to-many

Если удобен прямой API, связь можно объявить в модели:

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

$categories = Product::find(1)->pivotCategories;
```

Автоматического выбора между несколькими pivot-таблицами нет.

### Явные belongs-to и has-many

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

Доступ к relation definition как методу используется для управления связью:

```php
$article->author()->associate($user);
$article->author()->dissociate();

$product->pivotCategories()->attach(5);
$product->pivotCategories()->attach([5, 7]);
$product->pivotCategories()->attach(5, ['sort' => 10]);
$product->pivotCategories()->detach(5);
$product->pivotCategories()->sync([2, 5, 7]);
```

### `related()` и `allLinked()`

```php
$products = $category->related('products');
$descendants = $page->allLinked('pages');
```

`related()` выполняет обычное прямое разрешение связи; для старого pivot-перехода используйте `_relation` или `linked()`.

### Relation manager и relation definition

Для автоматически найденной прямой belongs-to связи доступны:

```php
$category = $product->category()->get();
$product->category()->associate($category);
$product->category()->dissociate();
```

`attach()`, `detach()` и `sync()` у автоматически найденного `RelationManager` намеренно выбрасывают исключение: pivot-таблица не угадывается. Эти операции работают у `RelationDefinition`, возвращаемого явно объявленным `belongsToMany()`; `get()` на definition возвращает запрос связанной модели.

### Eager loading

```php
$products = Product::with('category')->where('is_active', true);
$products = Product::with('category.parent')->limit(100);
```

Первое обращение к belongs-to связи из row-bound элемента также пакетно загружает цели для исходной коллекции.

### `has()`, `doesntHave()`, `whereHas()`, `whereDoesntHave()`

```php
Category::has('products');
Category::doesntHave('products');

Product::whereHas('category', fn ($category) =>
    $category->where('is_active', true)
);
```

Эти методы работают с прямыми belongs-to/has-many связями.

## 12. Кеш

### L1 identity map

Включён по умолчанию и действует в памяти процесса:

```php
Product::find(1)->title; // SQL
Product::find(1)->price; // без повторного SQL для полной строки
```

Частичная строка помнит выбранные поля. Отсутствующие поля догружаются при полном `find()`.

```php
Product::select('id', 'title')->find(1)->title;
Product::find(1)->price; // дополнительный SQL
```

Управление:

```php
Product::flushIdentityCache();
Product::find(1)->withoutCache();
Product::all()->withoutIdentityMap();
```

Диагностика:

```php
$product->cacheHit();
$product->cacheSource(); // database, identity, shared, mixed
```

### Явный result cache

```php
$products = Product::where('is_featured', true)->remember(60);
$menu = Category::whereNull('category_id')->remember(300, 'main-menu');
$forever = Category::all()->rememberForever('all-categories');
```

Записи инвалидируют кеш по поколениям затронутых таблиц.

## 13. Транзакции и DB

```php
DB::transaction(function () use ($order) {
    $order->save();
    $order->items->saveAll();
});
```

Поддерживаются вложенные транзакции через savepoints и повтор всей транзакции при deadlock:

```php
DB::transaction($callback, attempts: 3);
```

Callback после успешного commit:

```php
DB::afterCommit(fn () => dispatch($job));
```

Служебные методы `DB`:

- `setConnection(PDO $pdo, string $name = 'default')`;
- `connection(string $name = 'default')`;
- `transaction(callable $callback, string $connection = 'default', int $attempts = 1)`;
- `afterCommit(callable $callback, string $connection = 'default')`;
- `raw(string $sql, array $bindings = [])`;
- `now()` — raw `CURRENT_TIMESTAMP`;
- `table(string $table)` — публичный Query Builder;
- `execute()` и `runQuery()` — низкоуровневое выполнение, обычно используются ядром.

## 14. Пагинация и обработка больших таблиц

```php
$products = Product::where('is_active', true)
    ->orderBy('id')
    ->paginate(perPage: 20, page: 2);

$products->total();
$products->foundRows();
$products->lastPage();
$products->hasNextPage();
```

Номер страницы начинается с нуля. `simplePaginate()` сейчас использует тот же ActiveRecord-результат без отдельного типа paginator.

Порционная обработка:

```php
Product::all()->eachById(500, function ($product) {});
Product::all()->chunkById(500, function ($products) {});
```

## 15. Массивы, JSON и дерево

```php
$array = $product->toArray();
$array = $products->toArray();
$json = $products->toJson(JSON_PRETTY_PRINT);
$json = json_encode($products);
```

Сериализация учитывает `$hidden`, `$visible`, `$appends` и уже загруженные связи; сама по себе она не загружает новые связи. Есть защита от циклической сериализации.

Compatibility-методы:

```php
$jsonById = $products->to_json_by_id(JSON_PRETTY_PRINT);
$tree = $pages->tree($rootId);
```

## 16. Методы модели как scopes

Специальный префикс `scope` не нужен. Обычные методы модели могут собирать и продолжать запросы.

### Статический метод — точка входа

```php
class Product extends ActiveRecord
{
    public static function published(): static
    {
        return static::where('is_published', true);
    }
}

$products = Product::published()->orderBy('created_at', 'desc');
```

Статический метод начинает запрос через `static::where(...)`. Это удобный именованный конструктор запроса. Его следует вызывать от класса — `Product::published()`.

### Публичный instance-метод — продолжение запроса

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

Такой метод получает текущий объект через `$this`, поэтому сохраняет все условия, JOIN, сортировку и другие части запроса, добавленные до него.

### Защищённый instance-метод

Метод можно оставить `protected`, если он должен вызываться как часть fluent API, но не должен выглядеть обычным публичным методом PHP:

```php
class Product extends ActiveRecord
{
    protected function expensive(int $from): static
    {
        return $this->where('price', '>=', $from);
    }
}

$products = Product::where('is_active', true)
    ->expensive(1000)
    ->orderBy('price');
```

Внешний вызов защищённого метода перехватывает ActiveRecord и вызывает его на текущем запросе. Dispatcher разрешает таким образом только методы, объявленные в пользовательском классе модели или его прикладном базовом классе; внутренние protected-методы ORM наружу не открываются. Private-методы через fluent API не вызываются.

Методу не передаётся отдельный аргумент `$query`: текущий запрос уже находится в `$this`. Рекомендуется возвращать `$this->where(...)` или другой ActiveRecord-результат, чтобы цепочка оставалась очевидной.

## 17. Strict mode и схема

### Strict mode

```php
Product::strictMode(true);
```

В strict mode неизвестный атрибут/связь вызывает `UnknownAttributeOrRelationException`, а невыбранный существующий атрибут — `MissingAttributeException`.

### Schema mode

```php
Product::schemaMode(\Elveneek\SchemaMode::Strict);
Product::schemaMode(\Elveneek\SchemaMode::Suggest);
Product::schemaMode(\Elveneek\SchemaMode::Evolve);
```

Текущий compatibility default допускает автоматическое создание отсутствующего поля. Для production рекомендуется `Strict`. `schemaEvolution(bool)` — низкоуровневый переключатель этого поведения.

Служебные методы:

- `flushSchemaCache()`;
- `schemaColumns($table, $refresh = false)`;
- `columns($table = null)`;
- `one_to_plural($word)`;
- `plural_to_one($word)`.

`captureIdentitySnapshot()` и `restoreIdentitySnapshot()` публичны для транзакционного механизма ядра; прикладному коду обычно не нужны.

## 18. Диагностика запросов

```php
$query->toSql();
$query->bindings();
$query->toRawSql();       // только для диагностики
$query->queryFingerprint();
$query->queryDependencies();
```

Query log:

```php
DB::enableQueryLog();
DB::flushQueryLog();
$events = DB::queryLog();

DB::listen(function ($event) {
    // sql, bindings, duration, connection, model, source, rows
});
```

`DB::recordCache()` является служебной точкой логирования cache hit.

## 19. Публичный Query Builder

Query Builder нужен для отчётов, подзапросов и neutral rows без гидратации моделей.

```php
$query = DB::table('products')
    ->join('categories', 'categories.id', '=', 'products.category_id')
    ->select('categories.title')
    ->selectRaw('COUNT(*) AS products_count')
    ->groupBy('categories.id')
    ->orderBy('products_count', 'desc');

$rows = $query->rows();       // list<stdClass>
$row = $query->firstRow();    // stdClass|null
$value = $query->value('title');
$column = $query->column('title');
```

Builder immutable:

```php
$base = DB::table('products')->where('is_active', true);
$cheap = $base->where('price', '<', 1000);
$expensive = $base->where('price', '>=', 1000);
```

### Методы построения Query Builder

Все они возвращают новый builder:

- `select()`, `addSelect()`, `selectRaw()`, `distinct()`;
- `where()`, `orWhere()`, `whereGroup()`, `orWhereGroup()`;
- `whereIn()`, `orWhereIn()`, `whereNotIn()`, `orWhereNotIn()`;
- `whereNull()`, `orWhereNull()`, `whereNotNull()`, `orWhereNotNull()`;
- `whereBetween()`, `whereNotBetween()`, `whereLike()`, `orWhereLike()`;
- `whereExists()`, `whereNotExists()`, `whereColumn()`;
- `whereRaw()`, `orWhereRaw()`;
- `join()`, `leftJoin()`, `rightJoin()`, `crossJoin()`;
- `joinSub()`, `leftJoinSub()`;
- `groupBy()`, `groupByRaw()`, `having()`, `havingRaw()`;
- `orderBy()`, `orderByRaw()`, `limit()`, `offset()`;
- `whereKey()`, `whereKeys()`;
- `withoutCache()`, `withoutIdentityMap()`, `remember()`, `rememberForever()`;
- `lockForUpdate()`, `sharedLock()`;
- `withoutLimitOffset()`, `withoutOrder()`.

`with()` также хранится в builder, но relation eager loading применяется ActiveRecord-фасадом.

### Терминальные и диагностические методы Query Builder

- `rows()`;
- `firstRow()`;
- `value()`;
- `column()`;
- `count()`;
- `exists()`;
- `sum()`, `avg()`, `min()`, `max()`;
- `toSql()`;
- `bindings()`;
- `fingerprint()`;
- `dependencies()`.

## 20. Raw expressions

```php
$product->updated_at = DB::now();
$product->counter = DB::raw('counter + ?', [1]);
```

`DB::raw()` возвращает `Elveneek\Raw`/`Expression`. Raw SQL выполняется как выражение, а его bindings остаются параметрами. Никогда не передавайте пользовательский ввод непосредственно внутрь SQL-строки.

## 21. Блокировки

```php
DB::transaction(function () {
    $product = Product::where('id', 1)
        ->lockForUpdate()
        ->firstOrFail();

    $product->stock--;
    $product->save();
});

Product::where('id', 1)->sharedLock()->first();
```

## 22. Compatibility API старой версии

Новые имена предпочтительны, но сохранены старые варианты:

| Старое имя | Каноническое имя/поведение |
|---|---|
| `order_by()` | `orderBy()`; старое имя допускает legacy SQL вроде `rand()` |
| `group_by()` | `groupBy()` |
| `find_by()` | `where($field, $value)` |
| `w()`, `_w()`, `_where()` | `where()` |
| `f()`, `_f()` | `findOne()`/`find()` |
| `to_array()` | `toArray()` |
| `to_json()` | `toJson()` |
| `all_of($field)` | `pluck($field)` |
| `found_rows()` | `foundRows()` |
| `linked($table)` | явный legacy-переход `_relation` |
| `all_linked()` | `allLinked()` |
| `saveOne()` | `saveCurrent()` |
| `presave_row()` | `addRow()` |
| `ne()` | `isNotEmpty()` |
| `only($field)` | `value($field)` |
| `get($field)` | чтение атрибута |
| `by_id($id)` | поиск строки внутри коллекции |
| `to_json_by_id()` | JSON с primary key в качестве ключей |
| `ioi()` | ID вставки или текущий primary key |

Свойства без скобок для старого шаблонного кода также поддерживаются:

```php
$products->count;
$products->only_count;
$products->isEmpty;
$products->isNotEmpty;
$products->ne;
$products->to_array;
$products->to_json;
$products->stub;
```

Канонические методы со скобками предпочтительнее в новом коде.

## 23. Основные исключения

- `ModelNotFoundException` — обязательная строка не найдена;
- `MissingModelClassException` — нет класса модели для таблицы;
- `MissingAttributeException` — поле не выбрано;
- `UnknownAttributeOrRelationException` — неизвестное поле или связь;
- `UnknownRelationException`, `AmbiguousRelationException` — ошибки разрешения связи;
- `AmbiguousWriteException` — неоднозначная запись набора;
- `DirtyResultCannotBeRequeriedException` — попытка перестроить dirty-результат;
- `ReadOnlyRecordException` — сохранение проекции без primary key;
- `MassAssignmentException` — небезопасный `fill()`;
- `StaleModelException` — конфликт optimistic lock;
- `InvalidIdentifierException`, `UnsupportedOperatorException` — небезопасный запрос;
- `IncompatibleQueryException` — несовместимый `fromQuery()`;
- `QueryException` — ошибка SQL с исходным SQL и отдельными bindings;
- `SchemaException`, `HydrationException` — ошибки схемы и гидратации.

## 24. Краткий полный пример

```php
class Product extends ActiveRecord
{
    protected static array $casts = [
        'price' => 'decimal:2',
        'is_active' => 'bool',
    ];
}

$products = Product::where('is_active', true)
    ->where('price', '>=', 1000)
    ->when($brandId, fn ($q, $id) => $q->where('brand_id', $id))
    ->with('category')
    ->orderBy('price', 'desc')
    ->limit(20);

foreach ($products as $product) {
    echo $product->title;
    echo $product->category->title;

    if ($product->stock < 0) {
        $product->stock = 0;
    }
}

$products->saveAll();

// Явный pivot-переход, без автоматического many-to-many.
$productsThroughPivot = Category::find(5)
    ->_categories_to_products
    ->_products;
```

## 25. Низкоуровневые публичные компоненты

Эти классы публичны и могут использоваться прикладным кодом, но обычно нужны расширениям ORM, инфраструктуре и тестам.

### `Elveneek\Metadata\Inflector`

- `addRule(string $singular, string $plural)` — добавить исключение;
- `plural(string $word)` — получить множественное число;
- `singular(string $word)` — получить единственное число;
- `snake(string $value)` — преобразовать имя класса в `snake_case`.

### `Elveneek\Metadata\ModelMetadata`

- `table()`;
- `primaryKey()`;
- `casts()`;
- `hidden()`;
- `visible()`;
- `appends()`;
- `columns(bool $refresh = false)`;
- `castFromDatabase($field, $value)`;
- `castForDatabase($field, $value)`.

Объект metadata хранит соглашения конкретного класса модели и выполняет casts на границе БД.

### `Elveneek\Cache\IdentityMap`

- `get($connection, $modelClass, $id)`;
- `put($connection, RecordState $state)`;
- `markMissing(...)`, `isMissing(...)`;
- `invalidate(...)`, `invalidateTable(...)`;
- `snapshot()`, `restore($snapshot)`;
- `clear()`.

### `Elveneek\Records\RecordState`

- `key()`;
- `set($field, $value)`;
- `merge($attributes, $loadedColumns)`;
- `markSaved($databaseValues = [])`;
- `discardChanges()`;
- `isDirty($field = null)`.

Публичные свойства содержат `attributes`, `original`, `dirty`, `wasChanged`, `loadedColumns`, `relationCache`, `status` и признак placeholder новой пакетной строки.

### `Elveneek\Records\RowView`

`RowView` связывает canonical `RecordState` с extras конкретного SQL-результата. Метод `exposes($field)` сообщает, доступно ли поле в данной проекции.

### `Elveneek\Records\RecordCollection`

- `at($index)`, `rowAt($index)`;
- `loadAll()`, `rows()`;
- `loadedStates()`, `states()`, `hasChanges()`;
- `add(RowView $row)`, `unset($index)`;
- `countLoaded()`, `isFullyLoaded()`;
- `getIterator($onYield = null)`.

`RecordCollection` поддерживает частичное чтение PDO cursor. Для обычного прикладного кода предпочтительнее ActiveRecord-фасад.

### `Elveneek\Query\MySqlGrammar`

- `compileSelect(QueryBuilder $query)`;
- `compileCount(QueryBuilder $query, bool $ignoreLimit = false)`;
- `compilePredicates(array $predicates, array &$bindings)`;
- `assertIdentifier(string $identifier)`;
- `quoteIdentifier(string $identifier)`.

Результат компиляции — `CompiledQuery` с публичными readonly-свойствами `sql`, `bindings`, `bindingTypes`, `dependencies`.

### Expressions

- `Expression($sql, $bindings = [])` — базовое SQL-выражение;
- `Raw` — явно raw-выражение;
- `MutableQueryProxy::query()` — получить накопленный immutable builder из callback-прокси.

### Relations

`RelationDefinition` и `RelationManager` имеют методы:

- `get()`;
- `associate()`, `dissociate()`;
- `attach()`, `detach()`, `sync()`.

У `RelationManager` операции `attach/detach/sync` запрещены и требуют явно объявленного `belongsToMany()`. У `RelationDefinition` они доступны для explicit many-to-many.

### `Elveneek\SchemaMode`

Enum содержит значения `Strict`, `Suggest`, `Evolve`.

### `Elveneek\Scaffold` — legacy schema API

- `create_field($table, $field)`;
- `rename_column($table, $field, $newName)`;
- `create_table($table, $oneElement = '')`.

Новые таблицы Scaffold создаёт на InnoDB. В production предпочтительнее миграции и `SchemaMode::Strict`.

### `Elveneek\PDOProxy`

Переопределяет `prepare()`, `query()` и `exec()`. Reconnect/retry ограничен безопасными запросами чтения; запись автоматически повторно не выполняется.