<?php

namespace Elveneek\Metadata;

final class Inflector
{
    private static array $singularToPlural = [
        'person' => 'people', 'man' => 'men', 'woman' => 'women', 'child' => 'children',
        'mouse' => 'mice', 'tooth' => 'teeth', 'foot' => 'feet', 'goose' => 'geese',
        'news' => 'news', 'sheep' => 'sheep', 'deer' => 'deer', 'criteria' => 'criteria',
        'konkurs' => 'konkurses',
    ];

    public static function addRule(string $singular, string $plural): void
    {
        self::$singularToPlural[strtolower($singular)] = strtolower($plural);
    }

    public static function plural(string $word): string
    {
        $word = strtolower($word);
        if (str_contains($word, '_')) {
            $parts = explode('_', $word);
            $parts[array_key_last($parts)] = self::plural($parts[array_key_last($parts)]);
            return implode('_', $parts);
        }
        if (isset(self::$singularToPlural[$word])) {
            return self::$singularToPlural[$word];
        }
        return match (true) {
            (bool) preg_match('/[^aeiou]y$/', $word) => substr($word, 0, -1) . 'ies',
            (bool) preg_match('/(s|x|z|ch|sh)$/', $word) => $word . 'es',
            (bool) preg_match('/(?:f|fe)$/', $word) => preg_replace('/(?:f|fe)$/', 'ves', $word),
            default => $word . 's',
        };
    }

    public static function singular(string $word): string
    {
        $word = strtolower($word);
        if (str_contains($word, '_')) {
            $parts = explode('_', $word);
            $parts[array_key_last($parts)] = self::singular($parts[array_key_last($parts)]);
            return implode('_', $parts);
        }
        $reverse = array_flip(self::$singularToPlural);
        if (isset($reverse[$word])) {
            return $reverse[$word];
        }
        return match (true) {
            (bool) preg_match('/ies$/', $word) => substr($word, 0, -3) . 'y',
            (bool) preg_match('/(ches|shes|sses|xes|zes)$/', $word) => substr($word, 0, -2),
            str_ends_with($word, 's') && !str_ends_with($word, 'ss') => substr($word, 0, -1),
            default => $word,
        };
    }

    public static function snake(string $value): string
    {
        $value = preg_replace('/(?<!^)[A-Z]/', '_$0', $value);
        return strtolower((string) $value);
    }
}
