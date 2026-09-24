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
     * @param  ?string         $searchableAs      Index named by searchableAs(), if it names one literally.
     * @param  list<string>    $searchableFields  Keys of toSearchableArray(), empty when it is absent.
     */
    public function __construct(
        public readonly string $class,
        public readonly string $table,
        public readonly array $relations = [],
        public readonly array $fillable = [],
        public readonly array $hidden = [],
        public readonly array $casts = [],
        public readonly ?string $definedIn = null,
        public readonly bool $searchable = false,
        public readonly ?string $searchableAs = null,
        public readonly array $searchableFields = [],
    ) {
    }

    /**
     * The index this model is copied into, or null if it is not indexed.
     *
     * Scout defaults searchableAs() to the model's table name, so a model with
     * the trait and no override still names an index, and that index still holds
     * personal data whether or not anyone wrote the method.
     */
    public function searchIndex(): ?string
    {
        if (! $this->searchable) {
            return null;
        }

        return $this->searchableAs ?? $this->table;
    }

    /**
     * Carries Scout's findings onto a definition that was parsed before its
     * ancestry was known, so a model extending a searchable base is searchable too.
     *
     * @param  list<string>  $fields
     */
    public function withSearchable(?string $searchableAs, array $fields): self
    {
        return new self(
            $this->class,
            $this->table,
            $this->relations,
            $this->fillable,
            $this->hidden,
            $this->casts,
            $this->definedIn,
            true,
            $searchableAs,
            $fields,
        );
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
