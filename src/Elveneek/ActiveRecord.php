<?php

namespace Elveneek;

use Elveneek\Cache\IdentityMap;
use Elveneek\Exception\DirtyResultCannotBeRequeriedException;
use Elveneek\Exception\AmbiguousWriteException;
use Elveneek\Exception\MissingAttributeException;
use Elveneek\Exception\ModelNotFoundException;
use Elveneek\Exception\UnknownAttributeOrRelationException;
use Elveneek\Metadata\Inflector;
use Elveneek\Metadata\ModelMetadata;
use Elveneek\Query\MySqlGrammar;
use Elveneek\Query\QueryBuilder;
use Elveneek\Records\RecordCollection;
use Elveneek\Records\RecordState;
use Elveneek\Records\RowView;

if (!defined('SQL_NULL')) {
    define('SQL_NULL', '__ELVENEEK_SQL_NULL__');
}

/**
 * One lazy public façade backed by an immutable query and row-bound states.
 *
 * @method static static where(mixed ...$arguments)
 * @method static static whereIn(string $column, iterable $values)
 * @method static static orderBy(string $column, string $direction = 'asc')
 * @method static static create(array $attributes = [])
 */
abstract class ActiveRecord implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
{
    use Concerns\QueryApi;
    use Concerns\CollectionApi;
    use Concerns\PersistenceApi;
    use Concerns\RelationsApi;
    use Concerns\FluentApi;
    use Concerns\ExplicitRelationsApi;
    use Concerns\RelationQueryApi;

    public static mixed $db = null;
    public static array $_queries_cache = [];
    public static array $_columns_cache = [];
    public static array $preparedStatements = [];

    public mixed $insert_id = false;
    public int $current_page = 0;
    public int $per_page = 10;
    public mixed $queryTree = false;

    protected QueryBuilder $query;
    protected ?RecordCollection $collection = null;
    protected ?RowView $boundRow = null;
    protected ?RecordCollection $boundContext = null;
    protected int $boundIndex = 0;
    protected int $manualIndex = 0;
    protected ModelMetadata $metadata;
    protected string $resolvedTableName = '';
    protected string $cacheSourceValue = 'database';
    protected int $affectedRowsValue = 0;
    protected array $lastSaveErrorsValue = [];
    protected ?int $knownTotal = null;
    protected ?string $runtimeTableOverride = null;

    protected static ?IdentityMap $identityMap = null;
    protected static array $metadataCache = [];
    protected static array $schemaCache = [];
    protected static array $queryResultCache = [];
    protected static array $tableGenerations = [];
    protected static array $tableModelMap = [];
    protected static array $classTableMap = [];
    protected static bool $strict = false;
    protected static bool $schemaEvolution = true;

    protected const QUERY_METHODS = [
        'where', 'orWhere', 'whereGroup', 'orWhereGroup', 'whereIn', 'orWhereIn',
        'whereNotIn', 'orWhereNotIn', 'whereNull', 'orWhereNull', 'whereNotNull',
        'orWhereNotNull', 'whereBetween', 'whereNotBetween', 'whereLike', 'orWhereLike',
        'whereRaw', 'orWhereRaw', 'select', 'addSelect', 'selectRaw', 'distinct',
        'join', 'leftJoin', 'rightJoin', 'crossJoin', 'joinSub', 'leftJoinSub', 'whereExists', 'whereNotExists', 'whereColumn', 'groupBy', 'groupByRaw',
        'having', 'havingRaw', 'orderBy', 'orderByRaw', 'limit', 'offset', 'with',
        'withoutOrder', 'withoutLimitOffset',
        'withoutCache', 'withoutIdentityMap', 'remember', 'rememberForever',
        'lockForUpdate', 'sharedLock',
    ];

    public function __construct()
    {
        $this->runtimeTableOverride = self::$classTableMap[static::class] ?? null;
        $this->initializeModel();
    }

    protected function initializeModel(): void
    {
        $this->metadata = self::metadataFor($this->metadataKey(), $this->metadataTableOverride());
        $this->resolvedTableName = $this->metadata->table();
        $this->query = new QueryBuilder($this->resolvedTableName, $this->modelKey(), $this->primaryKeyName());
        $defaultOrder = $this->configuredStatic('defaultOrder');
        if (is_string($defaultOrder) && $defaultOrder !== '') {
            $this->query = $this->query->orderBy($defaultOrder);
        }
    }

    public static function connect(): \PDO
    {
        self::$preparedStatements = [];
        $dsn = 'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'];
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $db = !empty($_ENV['DB_AUTO_RECONNECT'])
            ? new PDOProxy($dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $options)
            : new \PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $options);
        $db->exec('SET NAMES utf8');
        $db->exec("SET sql_mode = ''");
        return $db;
    }

    public static function all(): static
    {
        return new static();
    }

    public static function findMany(array $ids): static
    {
        $model = new static();
        $model->query = $model->query->whereKeys($ids);
        return $model;
    }

    protected function findOrNull(int|string $id): ?static
    {
        return $this->findOne($id)->first();
    }

    protected function findOrFail(int|string $id): static
    {
        return $this->findOrNull($id)
            ?? throw new ModelNotFoundException($this->modelLabel() . " [{$id}] was not found.");
    }

    public static function __callStatic(string $name, array $arguments): mixed
    {
        return (new static())->__call($name, $arguments);
    }

    public function __call(string $name, array $arguments): mixed
    {
        $originalName = $name;
        $aliases = [
            'order_by' => 'orderBy', 'group_by' => 'groupBy', 'and_select' => 'addSelect',
            'find_by' => 'where', 'w' => 'where', '_w' => 'where', '_where' => 'where',
            'to_array' => 'toArray', 'to_json' => 'toJson', 'all_of' => 'pluck',
            'found_rows' => 'foundRows', 'linked' => 'related', 'all_linked' => 'allLinked',
            'saveOne' => 'saveCurrent', 'presave_row' => 'addRow',
        ];
        $name = $aliases[$name] ?? $name;
        if ($name === 'find') {
            return $this->findOne($arguments[0]);
        }
        if ($name === 'f' || $name === '_f') {
            return $this->findOne($arguments[0]);
        }
        if ($name === 'stub') {
            return $this->changeQuery($this->query->whereRaw('0 = 1'));
        }
        if ($name === 'create' || $name === 'new') {
            $attributes = $arguments[0] ?? [];
            if (!is_array($attributes)) {
                throw new \InvalidArgumentException("{$this->modelLabel()}::{$name}() expects an attribute array.");
            }
            return $this->newRecord($attributes);
        }
        if ($originalName === 'order_by' && isset($arguments[0]) && $this->isLegacyOrder((string) $arguments[0])) {
            return $this->legacyOrder((string) $arguments[0]);
        }
        if ($originalName === 'group_by' && isset($arguments[0]) && str_contains((string) $arguments[0], ',')) {
            return $this->legacyGroup((string) $arguments[0]);
        }
        if (($name === 'join' || $name === 'leftJoin') && count($arguments) === 1) {
            return $this->joinRelation((string) $arguments[0], $name === 'join' ? 'inner' : 'left');
        }
        if (in_array($name, self::QUERY_METHODS, true)) {
            return $this->changeQuery($this->query->{$name}(...$arguments));
        }
        if (in_array($name, ['findOrNull', 'findOrFail', 'firstOrCreate', 'updateOrCreate', 'chunkById', 'eachById'], true)) {
            return $this->{$name}(...$arguments);
        }
        if (in_array($name, ['toArray', 'toJson', 'foundRows', 'saveCurrent', 'addRow', 'allLinked'], true)) {
            return $this->{$name}(...$arguments);
        }
        if (in_array($name, ['whereHas', 'whereDoesntHave', 'has', 'doesntHave'], true)) {
            return $this->{$name}(...$arguments);
        }
        if ($name === 'pluck') {
            return $this->pluck(...$arguments);
        }
        if ($name === 'search') {
            return $this->search(...$arguments);
        }
        if (in_array($name, ['sum', 'avg', 'min', 'max'], true)) {
            return $this->aggregate($name, (string) $arguments[0]);
        }
        if ($originalName === 'linked') {
            return $this->related('_' . (string) $arguments[0]);
        }
        if ($name === 'related') {
            return $this->related((string) $arguments[0]);
        }
        if (method_exists($this, $name)) {
            $method = new \ReflectionMethod($this, $name);
            $declaringClass = $method->getDeclaringClass()->getName();
            if (!$method->isStatic() && !$method->isPrivate() && is_subclass_of($declaringClass, self::class)) {
                return $method->invokeArgs($this, $arguments) ?? $this;
            }
        }
        if ($this->canResolveRelation($name)) {
            return new Relations\RelationManager($this, $name);
        }
        throw new \BadMethodCallException($this->modelLabel() . "::{$name}() does not exist.");
    }

    protected function changeQuery(QueryBuilder $query): static
    {
        if ($this->boundRow !== null) {
            $copy = $this->newInstance();
            $copy->query = $query;
            return $copy;
        }
        if ($this->collection?->hasChanges()) {
            throw new DirtyResultCannotBeRequeriedException('Cannot change a materialized query with unsaved changes.');
        }
        $this->query = $query;
        $this->collection = null;
        $this->manualIndex = 0;
        $this->knownTotal = null;
        return $this;
    }

    protected function newInstance(): static
    {
        $model = new static();
        if ($this->runtimeTableOverride !== null) {
            $model->useRuntimeTable($this->runtimeTableOverride);
        }
        return $model;
    }

    protected function useRuntimeTable(string $table): static
    {
        MySqlGrammar::assertIdentifier($table);
        $this->runtimeTableOverride = $table;
        $this->initializeModel();
        return $this;
    }

    protected function modelKey(): string
    {
        return $this->runtimeTableOverride === null
            ? static::class
            : static::class . ':' . $this->runtimeTableOverride;
    }

    protected function modelLabel(): string
    {
        return static::class;
    }

    protected function metadataKey(): string
    {
        return $this->modelKey();
    }

    protected function metadataTableOverride(): ?string
    {
        return $this->runtimeTableOverride;
    }

    protected function findManyForCurrentModel(array $ids): static
    {
        return $this->changeQuery($this->query->whereKeys($ids));
    }

    protected function ensureCollection(): RecordCollection
    {
        if ($this->boundRow) {
            return $this->boundContext ?? $this->newCollection([$this->boundRow], true);
        }
        if ($this->collection) {
            return $this->collection;
        }
        if ($cached = $this->collectionFromIdentityMap()) {
            return $this->collection = $cached;
        }
        if ($cached = $this->collectionFromQueryCache()) {
            return $this->collection = $cached;
        }
        $compiled = (new MySqlGrammar())->compileSelect($this->query);
        $statement = DB::execute($compiled->sql, $compiled->bindings, 'default', $this->modelKey());
        $this->cacheSourceValue = 'database';
        $this->collection = new RecordCollection(
            $this->modelKey(),
            $statement,
            fn (array $row) => $this->hydrateRow($row),
            fn (RowView $row, RecordCollection $context, int $index) => $this->modelForRow($row, $context, $index),
        );
        if ($this->query->rememberSeconds !== null || $this->query->eagerLoads !== []) {
            $this->collection->loadAll();
        }
        if ($this->query->rememberSeconds !== null) {
            $this->storeQueryCache();
        }
        if ($this->query->eagerLoads) {
            foreach ($this->collection as $row) {
                foreach ($this->query->eagerLoads as $relation) {
                    $this->eagerLoadPath($row, $relation);
                }
            }
        }
        return $this->collection;
    }

    protected function hydrateRow(array $data): RowView
    {
        $schema = $this->metadata->columns();
        $attributes = $extras = [];
        foreach ($data as $field => $value) {
            if (isset($schema[$field])) {
                $attributes[$field] = $this->metadata->castFromDatabase($field, $value);
            } else {
                $extras[$field] = $value;
            }
        }
        $id = $attributes[$this->primaryKeyName()] ?? null;
        $state = $id !== null && $this->query->identityMapEnabled
            ? self::identity()->get($this->connectionKey(), $this->modelKey(), $id)
            : null;
        if ($state) {
            $state->merge($attributes, array_keys($attributes));
        } else {
            $state = new RecordState($this->modelKey(), $this->tableName(), $this->primaryKeyName(), 'persisted', $attributes);
            if ($id !== null && $this->query->identityMapEnabled) {
                $state = self::identity()->put($this->connectionKey(), $state);
                $state->merge($attributes, array_keys($attributes));
            }
        }
        return new RowView($state, $extras, array_keys($attributes));
    }

    protected function collectionFromIdentityMap(): ?RecordCollection
    {
        if (!$this->query->identityMapEnabled || $this->query->lookupIds === null) {
            return null;
        }
        $states = [];
        $missing = [];
        $required = $this->selectedColumns();
        foreach ($this->query->lookupIds as $id) {
            $state = self::identity()->get($this->connectionKey(), $this->modelKey(), $id);
            if ($state && $this->stateHasColumns($state, $required)) {
                $states[(string) $id] = $state;
            } elseif (!self::identity()->isMissing($this->connectionKey(), $this->modelKey(), $id)) {
                $missing[] = $id;
            }
        }

        if ($missing !== []) {
            $fetch = new QueryBuilder($this->tableName(), $this->modelKey(), $this->primaryKeyName());
            if ($this->query->columns !== []) {
                $fetch = $fetch->select($this->query->columns);
            }
            $fetch = $fetch->whereKeys($missing);
            $compiled = (new MySqlGrammar())->compileSelect($fetch);
            $rows = DB::execute($compiled->sql, $compiled->bindings, 'default', $this->modelKey())->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $data) {
                $view = $this->hydrateRow($data);
                if ($view->state->key() !== null) {
                    $states[(string) $view->state->key()] = $view->state;
                }
            }
            foreach ($missing as $id) {
                if (!isset($states[(string) $id])) {
                    self::identity()->markMissing($this->connectionKey(), $this->modelKey(), $id);
                }
            }
            $this->cacheSourceValue = 'mixed';
        } else {
            $this->cacheSourceValue = 'identity';
            DB::recordCache('identity-map', $this->modelKey());
        }
        $visible = $this->query->columns === [] ? null : $required;
        $ordered = [];
        foreach ($this->query->lookupIds as $id) {
            if (isset($states[(string) $id])) {
                $ordered[] = new RowView($states[(string) $id], [], $visible);
            }
        }
        return $this->newCollection($ordered, true);
    }
    protected function collectionFromQueryCache(): ?RecordCollection
    {
        if ($this->query->rememberSeconds === null) {
            return null;
        }
        $key = $this->queryCacheKey();
        $cached = self::$queryResultCache[$key] ?? null;
        if (!$cached || $cached['expires'] < time() || $cached['generations'] !== $this->dependencyGenerations()) {
            unset(self::$queryResultCache[$key]);
            return null;
        }
        $rows = array_map(fn (array $row) => $this->hydrateRow($row), $cached['rows']);
        $this->cacheSourceValue = 'shared';
        DB::recordCache('query-cache', $this->modelKey());
        return $this->newCollection($rows, true);
    }

    protected function storeQueryCache(): void
    {
        $rows = array_map(static fn (RowView $row) => array_merge($row->state->attributes, $row->extras), $this->collection->rows());
        self::$queryResultCache[$this->queryCacheKey()] = [
            'expires' => $this->query->rememberSeconds === PHP_INT_MAX ? PHP_INT_MAX : time() + $this->query->rememberSeconds,
            'rows' => $rows,
            'generations' => $this->dependencyGenerations(),
        ];
    }

    protected function newCollection(array $rows = [], bool $fullyLoaded = false): RecordCollection
    {
        return new RecordCollection(
            $this->modelKey(),
            null,
            fn (array $row) => $this->hydrateRow($row),
            fn (RowView $row, RecordCollection $context, int $index) => $this->modelForRow($row, $context, $index),
            $rows,
            $fullyLoaded,
        );
    }

    protected function modelForRow(RowView $row, RecordCollection $context, int $index): static
    {
        $model = $this->newInstance();
        $model->query = $this->query;
        $model->boundRow = $row;
        $model->boundContext = $context;
        $model->boundIndex = $index;
        $model->cacheSourceValue = $this->cacheSourceValue;
        return $model;
    }

    protected function currentRow(bool $create = false): ?RowView
    {
        if ($this->boundRow) {
            return $this->boundRow;
        }
        $row = $this->ensureCollection()->rowAt($this->manualIndex);
        if (!$row && $create) {
            $row = new RowView(new RecordState($this->modelKey(), $this->tableName(), $this->primaryKeyName(), 'new'));
            $this->ensureCollection()->add($row);
            $this->manualIndex = $this->ensureCollection()->countLoaded() - 1;
        }
        return $row;
    }

    protected function currentRowForWrite(): RowView
    {
        if ($this->boundRow) {
            return $this->boundRow;
        }
        if ($this->collection === null && $this->isUnfilteredRootQuery()) {
            throw new AmbiguousWriteException(
                'Cannot write to an unfiltered query; call create()/new() for a new row or select one row first.'
            );
        }
        $row = $this->currentRow();
        if (!$row) {
            throw new AmbiguousWriteException(
                'Cannot write without a current row; call create()/new() for a new row or select one row first.'
            );
        }
        return $row;
    }

    protected function isUnfilteredRootQuery(): bool
    {
        return $this->query->lookupIds === null
            && $this->query->wheres === []
            && $this->query->joins === []
            && $this->query->groups === []
            && $this->query->havings === []
            && $this->query->limitValue === null
            && $this->query->offsetValue === null;
    }

    protected function selectedColumns(): array
    {
        if ($this->query->columns === [] || in_array('*', $this->query->columns, true) || in_array($this->tableName() . '.*', $this->query->columns, true)) {
            return array_keys($this->metadata->columns());
        }
        $result = [];
        foreach ($this->query->columns as $column) {
            if (is_string($column) && preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\.)?([A-Za-z_][A-Za-z0-9_]*)$/', $column, $matches)) {
                $result[] = $matches[1];
            }
        }
        return $result;
    }

    protected function stateHasColumns(RecordState $state, array $columns): bool
    {
        foreach ($columns as $column) {
            if (!isset($state->loadedColumns[$column])) {
                return false;
            }
        }
        return true;
    }

    public function __get(string $name): mixed
    {
        if ($name === 'table') {
            return $this->tableName();
        }
        if ($name === 'new') {
            return $this->__call('new', []);
        }
        $row = $this->currentRow();
        if ($row && $row->exposes($name) && array_key_exists($name, $row->state->attributes)) {
            if ($this->modelPropertyMethod($name)) {
                return $this->modelPropertyValue($name);
            }
            return $this->attributeValue($row->state, $name);
        }
        if ($row && array_key_exists($name, $row->extras)) {
            return $row->extras[$name];
        }
        if (in_array($name, ['count', 'only_count'], true)) {
            return $this->count();
        }
        if ($name === 'isEmpty') {
            return $this->isEmpty();
        }
        if ($name === 'isNotEmpty' || $name === 'ne') {
            return $this->isNotEmpty();
        }
        if (in_array($name, ['toArray', 'to_array'], true)) {
            return $this->toArray();
        }
        if (in_array($name, ['toJson', 'to_json'], true)) {
            return $this->toJson();
        }
        if ($name === 'stub') {
            return $this->__call('stub', []);
        }
        $accessor = $this->accessorName($name);
        if (method_exists($this, $accessor)) {
            return $this->{$accessor}();
        }
        if (str_contains($name, '_as_') && !isset($this->metadata->columns()[$name])) {
            return $this->formatAttributeAs($name);
        }
        if ($this->modelPropertyMethod($name)) {
            return $this->modelPropertyValue($name);
        }
        if ($this->canResolveRelation($name)) {
            return $this->related($name);
        }
        if (isset($this->metadata->columns()[$name])) {
            if (self::$strict && $row && !$row->exposes($name)) {
                throw new MissingAttributeException("Attribute '{$name}' was not selected for " . $this->modelLabel() . '.');
            }
            return null;
        }
        if (self::$strict) {
            throw new UnknownAttributeOrRelationException("Unknown attribute or relation '{$name}' on " . $this->modelLabel() . '.');
        }
        return null;
    }

    public function __set(string $name, mixed $value): void
    {
        $row = $this->currentRowForWrite();
        if ($value === null && method_exists($this, $name)) {
            $method = new \ReflectionMethod($this, $name);
            if (!str_starts_with($method->getDeclaringClass()->getName(), 'Elveneek\\')) {
                $definition = $this->{$name}();
                if ($definition instanceof Relations\RelationDefinition) {
                    $definition->dissociate();
                    return;
                }
            }
        }
        if ($value === null && $this->canResolveRelation($name)) {
            $row->state->set($name . '_id', null);
            unset($row->state->relationCache[$name]);
            return;
        }
        if ($value instanceof self) {
            $row->state->set($name . '_id', $value->{$value->primaryKeyName()});
            unset($row->state->relationCache[$name]);
            return;
        }
        $mutator = $this->mutatorName($name);
        if (method_exists($this, $mutator)) {
            $result = $this->{$mutator}($value);
            if ($result === null) {
                return;
            }
            $value = $result;
        }
        if (($value === '' && str_ends_with($name, '_at')) || $value === SQL_NULL) {
            $value = null;
        }
        $row->state->set($name, $value);
    }

    public function __isset(string $name): bool
    {
        if ($name === 'table') {
            return true;
        }
        $row = $this->currentRow();
        return ($row && (array_key_exists($name, $row->state->attributes) || array_key_exists($name, $row->extras)))
            || isset($this->metadata->columns()[$name]) || $this->canResolveRelation($name) || method_exists($this, $name);
    }

    protected function attributeValue(RecordState $state, string $field): mixed
    {
        $accessor = $this->accessorName($field);
        return method_exists($this, $accessor) ? $this->{$accessor}() : $state->attributes[$field];
    }

    protected function modelPropertyMethod(string $name): ?\ReflectionMethod
    {
        if (!method_exists($this, $name)) {
            return null;
        }
        $method = new \ReflectionMethod($this, $name);
        if ($method->getNumberOfRequiredParameters() > 0 || str_starts_with($method->getDeclaringClass()->getName(), 'Elveneek\\')) {
            return null;
        }
        return $method;
    }

    protected function modelPropertyValue(string $name): mixed
    {
        $value = $this->{$name}();
        return $value instanceof Relations\RelationDefinition ? $value->get() : $value;
    }

    protected function formatAttributeAs(string $name): mixed
    {
        $separator = strpos($name, '_as_');
        $field = substr($name, 0, $separator);
        $formatter = substr($name, $separator + 4);
        if ($field === '' || $formatter === '') {
            throw new \BadMethodCallException("Invalid formatted attribute '{$name}'. Expected field_as_formatter.");
        }

        $value = $this->{$field};
        $function = 'as_' . $formatter;
        if (function_exists($function)) {
            return $function($value, $field, $this);
        }

        $class = 'As_' . $formatter;
        if (class_exists($class)) {
            if (!method_exists($class, 'call')) {
                throw new \BadMethodCallException("Formatter class {$class} must declare public static call().");
            }
            $call = new \ReflectionMethod($class, 'call');
            if (!$call->isPublic() || !$call->isStatic()) {
                throw new \BadMethodCallException("Formatter class {$class}::call() must be public and static.");
            }
            return $class::call($value, $field, $this);
        }

        throw new \BadMethodCallException(
            "Formatter for '{$name}' was not found: define function {$function}() or class {$class}::call()."
        );
    }
    protected function accessorName(string $field): string
    {
        return 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $field)));
    }

    protected function mutatorName(string $field): string
    {
        return 'set' . str_replace(' ', '', ucwords(str_replace('_', ' ', $field)));
    }

    protected function tableName(): string
    {
        return $this->resolvedTableName;
    }

    protected function primaryKeyName(): string
    {
        return $this->metadata->primaryKey();
    }

    protected function connectionKey(): string
    {
        return self::connectionIdentifier();
    }

    protected static function connectionIdentifier(): string
    {
        try {
            $connection = DB::connection();
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === "Database connection 'default' is not configured.") {
                return 'default:none';
            }
            throw $exception;
        }
        return 'default:' . spl_object_id($connection);
    }

    protected static function metadataFor(string $key, ?string $table = null): ModelMetadata
    {
        return self::$metadataCache[$key] ??= new ModelMetadata($table === null ? $key : static::class, $table);
    }

    protected static function identity(): IdentityMap
    {
        return self::$identityMap ??= new IdentityMap();
    }

    protected static function invalidateTable(string $table): void
    {
        self::identity()->invalidateTable(self::connectionIdentifier(), $table);
        self::bumpGeneration($table);
    }

    public static function invalidateTableCache(string $table): void
    {
        MySqlGrammar::assertIdentifier($table);
        self::invalidateTable($table);
    }

    protected static function bumpGeneration(string $table): void
    {
        $key = self::connectionIdentifier() . '|' . $table;
        self::$tableGenerations[$key] = (self::$tableGenerations[$key] ?? 0) + 1;
    }

    protected function dependencyGenerations(): array
    {
        $result = [];
        foreach ($this->query->dependencies() as $table) {
            $key = $this->connectionKey() . '|' . $table;
            $result[$table] = self::$tableGenerations[$key] ?? 0;
        }
        return $result;
    }

    protected function queryCacheKey(): string
    {
        return $this->connectionKey() . '|' . ($this->query->rememberKey ?? $this->query->fingerprint());
    }

    protected function configuredStatic(string $property): mixed
    {
        if (!property_exists(static::class, $property)) {
            return null;
        }
        $reflection = new \ReflectionProperty(static::class, $property);
        if (!$reflection->isStatic()) {
            return null;
        }
        $reflection->setAccessible(true);
        return $reflection->isInitialized() ? $reflection->getValue() : null;
    }

    public static function strictMode(bool $enabled = true): void
    {
        self::$strict = $enabled;
    }

    public static function schemaEvolution(bool $enabled = true): void
    {
        self::$schemaEvolution = $enabled;
    }

    public static function captureIdentitySnapshot(): array
    {
        return self::identity()->snapshot();
    }

    public static function restoreIdentitySnapshot(array $snapshot): void
    {
        self::identity()->restore($snapshot);
    }

    public static function captureRuntimeSnapshot(): array
    {
        return [
            'identity' => self::identity()->snapshot(),
            'queryResultCache' => self::$queryResultCache,
            'tableGenerations' => self::$tableGenerations,
        ];
    }

    public static function restoreRuntimeSnapshot(array $snapshot): void
    {
        self::identity()->restore($snapshot['identity']);
        self::$queryResultCache = $snapshot['queryResultCache'];
        self::$tableGenerations = $snapshot['tableGenerations'];
    }
    public static function flushIdentityCache(): void
    {
        self::identity()->clear();
    }

    public static function flushSchemaCache(): void
    {
        self::$schemaCache = self::$_columns_cache = self::$metadataCache = [];
    }

    public static function schemaColumns(string $table, bool $refresh = false): array
    {
        $key = self::connectionIdentifier() . '|' . $table;
        if (!$refresh && isset(self::$schemaCache[$key])) {
            return self::$schemaCache[$key];
        }
        try {
            $statement = DB::connection()->query('SELECT * FROM ' . MySqlGrammar::quoteIdentifier($table) . ' LIMIT 0');
            $columns = [];
            for ($i = 0; $i < $statement->columnCount(); $i++) {
                $meta = $statement->getColumnMeta($i);
                $columns[$meta['name']] = $meta;
            }
        } catch (\Throwable) {
            $columns = [];
        }
        self::$_columns_cache[$table] = array_fill_keys(array_keys($columns), true);
        return self::$schemaCache[$key] = $columns;
    }

    public function columns(?string $table = null): array|false
    {
        $columns = self::schemaColumns($table ?? $this->tableName());
        return $columns ? array_fill_keys(array_keys($columns), true) : false;
    }

    public static function one_to_plural(string $word): string
    {
        return Inflector::plural($word);
    }

    public static function plural_to_one(string $word): string
    {
        return Inflector::singular($word);
    }

    public static function mapTable(string $table, string $modelClass): void
    {
        MySqlGrammar::assertIdentifier($table);
        if (!class_exists($modelClass)) {
            throw new \InvalidArgumentException("Mapped model class {$modelClass} does not exist.");
        }
        if (!is_subclass_of($modelClass, self::class)) {
            throw new \InvalidArgumentException("Mapped model class {$modelClass} must extend " . self::class . '.');
        }
        self::$tableModelMap[$table] = $modelClass;
        self::$classTableMap[$modelClass] = $table;
    }

    public static function unmapTable(string $table): void
    {
        MySqlGrammar::assertIdentifier($table);
        $modelClass = self::$tableModelMap[$table] ?? null;
        unset(self::$tableModelMap[$table]);
        if ($modelClass !== null && (self::$classTableMap[$modelClass] ?? null) === $table) {
            unset(self::$classTableMap[$modelClass]);
        }
    }

    public static function clearTableMap(): void
    {
        self::$tableModelMap = [];
        self::$classTableMap = [];
    }

    public static function fromTable(string $table, string $suffix = ''): ActiveRecord
    {
        return self::modelForTableName($table, $suffix);
    }

    protected function modelForTable(string $table): ActiveRecord
    {
        return self::modelForTableName($table, '', static::class);
    }

    protected static function modelForTableName(string $table, string $suffix = '', ?string $contextClass = null): ActiveRecord
    {
        MySqlGrammar::assertIdentifier($table);
        $mappedClass = self::$tableModelMap[$table] ?? null;
        if ($mappedClass !== null) {
            return self::mappedModelForTable($table, $mappedClass);
        }
        $class = self::activeRecordClassForTable($table, $suffix, $contextClass);
        return $class !== null ? new $class() : TableRecord::forTable($table);
    }

    protected static function mappedModelForTable(string $table, string $class): ActiveRecord
    {
        if ($class === TableRecord::class) {
            return TableRecord::forTable($table);
        }
        $model = new $class();
        return $model->useRuntimeTable($table);
    }

    protected static function activeRecordClassForTable(string $table, string $suffix = '', ?string $contextClass = null): ?string
    {
        $short = ucfirst(Inflector::singular($table)) . $suffix;
        $classes = [$short];
        if ($contextClass !== null && $contextClass !== self::class && $contextClass !== TableRecord::class) {
            $namespace = (new \ReflectionClass($contextClass))->getNamespaceName();
            if ($namespace !== '') {
                array_unshift($classes, $namespace . '\\' . $short);
            }
        }
        foreach (array_values(array_unique($classes)) as $class) {
            if (class_exists($class) && is_subclass_of($class, self::class)) {
                return $class;
            }
        }
        return null;
    }
}
