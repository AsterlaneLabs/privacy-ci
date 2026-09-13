<?php

declare(strict_types=1);

namespace PrivacyCI\Discovery\Models;

/** What static analysis could learn about one Eloquent model. */
final class ModelDefinition
{
    /**
     * @param  list<Relation>  $relations
     * @param  list<string>    $fillable
     * @param  list<string>    $hidden
     * @param  array<string, string>  $casts
     */
    public function __construct(
        public readonly string $class,
        public readonly string $table,
        public readonly array $relations = [],
        public readonly array $fillable = [],
        public readonly array $hidden = [],
        public readonly array $casts = [],
        public readonly ?string $definedIn = null,
    ) {
    }

    public function shortName(): string
    {
        $pos = strrpos($this->class, '\\');

        return $pos === false ? $this->class : substr($this->class, $pos + 1);
    }

    /**
     * `$hidden` and encrypted casts are a developer signalling "this is sensitive"
     * in the framework's own vocabulary. Weak evidence on its own, but it usefully
     * raises confidence on a column a name heuristic was unsure about.
     */
    public function looksSensitive(string $column): bool
    {
        if (in_array($column, $this->hidden, true)) {
            return true;
        }

        $cast = $this->casts[$column] ?? null;

        return $cast !== null && str_starts_with($cast, 'encrypted');
    }
}
