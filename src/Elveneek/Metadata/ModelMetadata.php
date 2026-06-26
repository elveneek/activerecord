<?php

namespace Elveneek\Metadata;

use Elveneek\ActiveRecord;

final class ModelMetadata
{
    private ?array $columnsCache = null;

    public function __construct(public readonly string $modelClass)
    {
    }

    public function table(): string
    {
        $configured = $this->staticProperty('table');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        $short = (new \ReflectionClass($this->modelClass))->getShortName();
        return Inflector::plural(Inflector::snake($short));
    }

    public function primaryKey(): string
    {
        return (string) ($this->staticProperty('primaryKey') ?: 'id');
    }

    public function casts(): array
    {
        $casts = $this->staticProperty('casts');
        return is_array($casts) ? $casts : [];
    }

    public function hidden(): array
    {
        $hidden = $this->staticProperty('hidden');
        return is_array($hidden) ? $hidden : [];
    }

    public function visible(): array
    {
        $visible = $this->staticProperty('visible');
        return is_array($visible) ? $visible : [];
    }

    public function appends(): array
    {
        $appends = $this->staticProperty('appends');
        return is_array($appends) ? $appends : [];
    }

    public function columns(bool $refresh = false): array
    {
        return ActiveRecord::schemaColumns($this->table(), $refresh);
    }
    public function castFromDatabase(string $field, mixed $value): mixed
    {
        return $this->cast($field, $value, false);
    }

    public function castForDatabase(string $field, mixed $value): mixed
    {
        return $this->cast($field, $value, true);
    }

    private function cast(string $field, mixed $value, bool $database): mixed
    {
        if ($value === null) {
            return null;
        }
        $cast = $this->casts()[$field] ?? null;
        if ($cast === null) {
            $cast = match (true) {
                $field === $this->primaryKey(), str_ends_with($field, '_id') => 'int',
                str_starts_with($field, 'is_') => 'bool',
                default => null,
            };
        }
        if (is_string($cast) && enum_exists($cast) && is_subclass_of($cast, \BackedEnum::class)) {
            return $database ? ($value instanceof \BackedEnum ? $value->value : $value) : $cast::from($value);
        }
        if (is_object($cast) && method_exists($cast, $database ? 'set' : 'get')) {
            return $cast->{$database ? 'set' : 'get'}($value, $field, $this->modelClass);
        }
        $type = strtolower((string) $cast);
        if (str_starts_with($type, 'decimal')) {
            $scale = (int) (explode(':', $type, 2)[1] ?? 2);
            return $this->formatDecimal($value, $scale);
        }
        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double', 'real' => (float) $value,
            'bool', 'boolean' => (bool) $value,
            'string' => (string) $value,
            'json', 'array' => $database
                ? (is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR))
                : (is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : (array) $value),
            'datetime' => $database
                ? ($value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value)
                : ($value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable((string) $value)),
            'date' => $database
                ? ($value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value)
                : ($value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable((string) $value)),
            default => $value,
        };
    }

    private function formatDecimal(mixed $value, int $scale): string
    {
        if ($scale < 0) {
            throw new \InvalidArgumentException('Decimal scale cannot be negative.');
        }
        if (is_float($value)) {
            return number_format($value, $scale, '.', '');
        }
        $string = trim((string) $value);
        if (!preg_match('/^([+-]?)(\d+)(?:\.(\d*))?$/', $string, $matches)) {
            if (is_numeric($string)) {
                return number_format((float) $string, $scale, '.', '');
            }
            throw new \InvalidArgumentException("Invalid decimal value: {$string}");
        }
        $negative = $matches[1] === '-';
        $integer = ltrim($matches[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = $matches[3] ?? '';
        $kept = substr(str_pad($fraction, $scale, '0'), 0, $scale);
        $roundDigit = $fraction[$scale] ?? '0';
        if ($roundDigit >= '5') {
            $combined = $this->incrementDecimalDigits($integer . $kept);
            if ($scale > 0) {
                $combined = str_pad($combined, $scale + 1, '0', STR_PAD_LEFT);
                $integer = substr($combined, 0, -$scale);
                $kept = substr($combined, -$scale);
            } else {
                $integer = $combined;
            }
        }
        $isZero = trim($integer . $kept, '0') === '';
        return ($negative && !$isZero ? '-' : '') . $integer . ($scale > 0 ? '.' . $kept : '');
    }

    private function incrementDecimalDigits(string $digits): string
    {
        $digits = $digits === '' ? '0' : $digits;
        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            if ($digits[$index] !== '9') {
                $digits[$index] = (string) ((int) $digits[$index] + 1);
                return $digits;
            }
            $digits[$index] = '0';
        }
        return '1' . $digits;
    }
    private function staticProperty(string $name): mixed
    {
        if (!property_exists($this->modelClass, $name)) {
            return null;
        }
        $property = new \ReflectionProperty($this->modelClass, $name);
        if (!$property->isStatic()) {
            return null;
        }
        $property->setAccessible(true);
        return $property->isInitialized() ? $property->getValue() : null;
    }
}
