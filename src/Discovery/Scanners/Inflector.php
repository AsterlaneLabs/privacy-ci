<?php

declare(strict_types=1);

namespace PrivacyCI\Discovery\Scanners;

/**
 * Just enough pluralisation to resolve Laravel's foreign-key convention
 * (user_id => users). Deliberately tiny and dependency-free so the discovery
 * core stays framework-independent.
 */
final class Inflector
{
    private const IRREGULAR = [
        'person' => 'people',
        'man' => 'men',
        'woman' => 'women',
        'child' => 'children',
        'tooth' => 'teeth',
        'foot' => 'feet',
        'mouse' => 'mice',
        'goose' => 'geese',
    ];

    private const UNCOUNTABLE = [
        'equipment', 'information', 'media', 'metadata', 'data', 'staff', 'news',
    ];

    /**
     * Eloquent's class-to-table convention: User => users, BlogPost => blog_posts.
     * A target that already looks like a table name is passed through unchanged.
     */
    public static function tableName(string $class): string
    {
        $class = ltrim($class, '\\');

        if (($pos = strrpos($class, '\\')) !== false) {
            $class = substr($class, $pos + 1);
        }

        // Already snake_case: treat it as a table name the caller wrote directly.
        if (preg_match('/^[a-z0-9_]+$/', $class) === 1) {
            return $class;
        }

        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $class));

        return self::pluralize($snake);
    }

    public static function pluralize(string $word): string
    {
        $lower = strtolower($word);

        if (in_array($lower, self::UNCOUNTABLE, true)) {
            return $word;
        }

        if (isset(self::IRREGULAR[$lower])) {
            return self::IRREGULAR[$lower];
        }

        // Laravel tables are snake_case; only the final segment inflects.
        $head = '';
        if (($pos = strrpos($word, '_')) !== false) {
            $head = substr($word, 0, $pos + 1);
            $word = substr($word, $pos + 1);
            $lower = strtolower($word);

            if (isset(self::IRREGULAR[$lower])) {
                return $head.self::IRREGULAR[$lower];
            }
        }

        return $head.self::applyRules($word);
    }

    private static function applyRules(string $word): string
    {
        if (preg_match('/(s|x|z|ch|sh)$/i', $word) === 1) {
            return $word.'es';
        }

        if (preg_match('/[^aeiou]y$/i', $word) === 1) {
            return substr($word, 0, -1).'ies';
        }

        if (preg_match('/(f|fe)$/i', $word) === 1) {
            return preg_replace('/(f|fe)$/i', 'ves', $word) ?? $word.'s';
        }

        if (preg_match('/[^aeiou]o$/i', $word) === 1) {
            return $word.'es';
        }

        return $word.'s';
    }
}
