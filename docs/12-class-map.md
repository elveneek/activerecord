# Карта Классов И Файлов

Этот раздел нужен, чтобы быстро понять устройство проекта и где искать поведение.

## Главный слой

| Файл | Что делает |
| --- | --- |
| `src/Elveneek/ActiveRecord.php` | Главный публичный фасад модели. Собирает traits, хранит query, collection, bound row, metadata, кэши, `connect()`, `find()`, magic access, casts/accessors/relation routing. |
| `src/Elveneek/DB.php` | Статический сервис подключения, resolver внешнего `PDO`, query builder, raw expressions, SQL execution, query log, транзакции и `afterCommit()`. |
| `src/Elveneek/TableRecord.php` | Generic ActiveRecord-модель для работы с таблицей без отдельного PHP-класса и без runtime `eval`. |
| `src/Elveneek/SchemaMode.php` | Enum `Strict`, `Suggest`, `Evolve` для управления auto schema evolution. |
| `src/Elveneek/PDOProxy.php` | PDO-наследник для auto reconnect при MySQL `server has gone away`. |
| `src/Elveneek/Scaffold.php` | Legacy API создания/переименования колонок и создания таблиц. Используется schema evolution. |
| `src/Elveneek/Raw.php` | Наследник `Query\Expression` для raw SQL expressions. |

## Traits текущего ActiveRecord API

| Файл | Что делает |
| --- | --- |
| `src/Elveneek/Concerns/QueryApi.php` | `toQuery()`, `fromQuery()`, `first()`, `count()`, `paginate()`, `pluck()`, `load()`, агрегаты, diagnostics, chunking. |
| `src/Elveneek/Concerns/CollectionApi.php` | `Iterator`, `ArrayAccess`, `toArray()`, `toJson()`, dirty tracking, `refresh()`, `tree()`, raw access. |
| `src/Elveneek/Concerns/PersistenceApi.php` | создание draft-row для magic `create()`/`new()`, `insert()`, `save()`, `saveAll()`, `fill()`, `updateAll()`, `delete()`, `truncate()`, `upsert()`. |
| `src/Elveneek/Concerns/RelationsApi.php` | Автоматические связи по колонкам, `_relation`, `related()`, `allLinked()`, relation join, eager loading. |
| `src/Elveneek/Concerns/ExplicitRelationsApi.php` | Ручные `belongsTo()`, `hasMany()`, `belongsToMany()`. |
| `src/Elveneek/Concerns/RelationQueryApi.php` | `has()`, `doesntHave()`, `whereHas()`, `whereDoesntHave()`. |
| `src/Elveneek/Concerns/FluentApi.php` | `when()`, `unless()`, `tap()`, `orStub()`, `get()`, `only()`, `schemaMode()`. |

## Query layer

| Файл | Что делает |
| --- | --- |
| `src/Elveneek/Query/QueryBuilder.php` | Immutable builder: select, where, join, group, order, limit, cache flags, locks, terminal rows/count/aggregates. |
| `src/Elveneek/Query/MySqlGrammar.php` | Компиляция `QueryBuilder` в SQL, bindings, binding types и table dependencies. Валидирует identifiers. |
| `src/Elveneek/Query/CompiledQuery.php` | DTO результата компиляции: `sql`, `bindings`, `bindingTypes`, `dependencies`. |
| `src/Elveneek/Query/Expression.php` | Базовое raw SQL expression с bindings. |
| `src/Elveneek/Query/MutableQueryProxy.php` | Mutable-прокси для callback-групп `whereGroup(fn ($query) => ...)`. |

## Metadata

| Файл | Что делает |
| --- | --- |
| `src/Elveneek/Metadata/Inflector.php` | `plural()`, `singular()`, `snake()`, пользовательские правила через `addRule()`. |
| `src/Elveneek/Metadata/ModelMetadata.php` | Имя таблицы, primary key, `$casts`, `$hidden`, `$visible`, `$appends`, список колонок, casts на чтение/запись. |

## Records и кэш

| Файл | Что делает |
| --- | --- |
| `src/Elveneek/Cache/IdentityMap.php` | Canonical cache строк по connection/model/id, negative cache отсутствующих id, snapshots для транзакций. |
| `src/Elveneek/Records/RecordState.php` | Состояние строки: attributes, original, dirty, wasChanged, loadedColumns, relationCache, status. |
| `src/Elveneek/Records/RowView.php` | Представление строки в конкретном SQL-результате: canonical state + extras + visibleColumns. |
| `src/Elveneek/Records/RecordCollection.php` | Лениво загружаемая коллекция RowView, cursor handling, iterator, `at()`, `rows()`, `loadAll()`. |

## Relations

| Файл | Что делает |
| --- | --- |
| `src/Elveneek/Relations/RelationDefinition.php` | Результат explicit `belongsTo()`, `hasMany()`, `belongsToMany()`. Умеет `get()`, `associate()`, `dissociate()`, `attach()`, `detach()`, `sync()`. |
| `src/Elveneek/Relations/RelationManager.php` | Менеджер автоматически найденной связи. Читает связь, умеет `associate()`/`dissociate()` для direct belongs-to, запрещает pivot writes. |

## Исключения

| Исключение | Когда встречается |
| --- | --- |
| `AmbiguousWriteException` | `save()` или `delete()` не могут однозначно выбрать одну строку. |
| `DirtyResultCannotBeRequeriedException` | Попытка менять запрос у материализованной dirty-коллекции. |
| `IncompatibleQueryException` | `fromQuery()` получил builder, который нельзя превратить в writable model rows. |
| `InvalidIdentifierException` | Небезопасное имя таблицы/колонки/alias в структурном SQL API. |
| `MassAssignmentException` | `fill()` вызван без `$fillable` и без `only`. |
| `MissingAttributeException` | Strict mode: поле есть в таблице, но не выбрано в partial select. |
| `MissingModelClassException` | Зарезервировано для случаев, когда модель таблицы не может быть создана. Обычно `fromTable()` использует `TableRecord` fallback. |
| `ModelNotFoundException` | `findOrFail()` или `firstOrFail()` ничего не нашли. |
| `QueryException` | Ошибка SQL; хранит SQL и bindings. |
| `ReadOnlyRecordException` | Попытка сохранить persisted projection без primary key. |
| `StaleModelException` | Optimistic lock обнаружил устаревшую строку. |
| `UnknownAttributeOrRelationException` | Strict mode: неизвестное поле или связь. |
| `UnsupportedOperatorException` | Неподдерживаемый оператор в structured where/whereColumn. |
| `HydrationException`, `SchemaException`, `UnknownRelationException`, `AmbiguousRelationException` | Зарезервированы для ошибок гидратации, схемы и связей. |

## Как проходит чтение строки

1. Вы строите запрос через `Product::where(...)`.
2. При первом чтении `ensureCollection()` проверяет identity map и result cache.
3. Если кэша нет, `MySqlGrammar` компилирует SQL.
4. `DB::execute()` выполняет SQL и возвращает `PDOStatement`.
5. `RecordCollection` лениво получает строки из cursor.
6. `hydrateRow()` делит данные на attributes и extras, применяет casts, кладет state в identity map.
7. `modelForRow()` создает row-bound модель для конкретной строки.

## Как проходит запись строки

1. Присваивание свойства пишет значение в `RecordState::dirty`.
2. `save()` выбирает одну dirty/new строку, `saveAll()` - все dirty/new строки.
3. `ensureWritableColumns()` проверяет схему и при `Evolve` может создать недостающие колонки.
4. `compileValues()` применяет casts на запись и raw expressions.
5. Выполняется `INSERT` или `UPDATE`.
6. `RecordState::markSaved()` переносит attributes в original и очищает dirty.
7. Таблица получает новый generation, identity/result caches инвалидируются.
