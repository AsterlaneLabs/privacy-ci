<?php

declare(strict_types=1);

namespace PrivacyCI\Discovery\Schema;

final class Table
{
    /** @var array<string, Column> */
    private array $columns = [];

    /** @var list<ForeignKey> */
    private array $foreignKeys = [];

    public function __construct(public readonly string $name)
    {
    }

    public function addColumn(Column $column): void
    {
        $this->columns[$column->name] = $column;
    }

    public function dropColumn(string $name): void
    {
        unset($this->columns[$name]);
    }

    public function addForeignKey(ForeignKey $key): void
    {
        $this->foreignKeys[] = $key;
    }

    public function hasColumn(string $name): bool
    {
        return isset($this->columns[$name]);
    }

    public function column(string $name): ?Column
    {
        return $this->columns[$name] ?? null;
    }

    /** @return array<string, Column> */
    public function columns(): array
    {
        return $this->columns;
    }

    /** @return list<ForeignKey> */
    public function foreignKeys(): array
    {
        return $this->foreignKeys;
    }

    public function foreignKeyFor(string $column): ?ForeignKey
    {
        foreach ($this->foreignKeys as $key) {
            if ($key->column === $column) {
                return $key;
            }
        }

        return null;
    }
}
