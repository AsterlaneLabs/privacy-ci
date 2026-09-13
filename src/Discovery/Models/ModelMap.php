<?php

declare(strict_types=1);

namespace PrivacyCI\Discovery\Models;

use PrivacyCI\Discovery\Scanners\Inflector;

/** Every model the scanner found, indexed for lookup by class or table. */
final class ModelMap
{
    /** @var array<string, ModelDefinition> */
    private array $byClass = [];

    public function add(ModelDefinition $model): void
    {
        $this->byClass[$model->class] = $model;
    }

    public function forClass(string $class): ?ModelDefinition
    {
        $class = ltrim($class, '\\');

        if (isset($this->byClass[$class])) {
            return $this->byClass[$class];
        }

        // Policies may name a model by its short name; fall back to that.
        foreach ($this->byClass as $model) {
            if ($model->shortName() === $class) {
                return $model;
            }
        }

        return null;
    }

    public function forTable(string $table): ?ModelDefinition
    {
        foreach ($this->byClass as $model) {
            if ($model->table === $table) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Resolve a policy target, a model class or a bare table name, to a table.
     * Falls back to Eloquent's own naming convention when no model was scanned,
     * so a policy still works in an application whose models live somewhere odd.
     */
    public function resolveTable(string $target): string
    {
        return $this->forClass($target)?->table ?? Inflector::tableName($target);
    }

    /** @return array<string, ModelDefinition> */
    public function all(): array
    {
        ksort($this->byClass);

        return $this->byClass;
    }
}
