<?php

declare(strict_types=1);

namespace PrivacyCI\Discovery\Schema;

/**
 * The application's schema, reconstructed by replaying migration history.
 *
 * Built from source files alone, with no database connection, no credentials
 * and no data access. That is the default discovery path and a large part of
 * the trust story: a CI job can run this against a checkout with nothing else
 * present.
 */
final class SchemaMap
{
    /** @var array<string, Table> */
    private array $tables = [];

    public function table(string $name): Table
    {
        return $this->tables[$name] ??= new Table($name);
    }

    public function hasTable(string $name): bool
    {
        return isset($this->tables[$name]);
    }

    public function dropTable(string $name): void
    {
        unset($this->tables[$name]);
    }

    public function renameTable(string $from, string $to): void
    {
        if (! isset($this->tables[$from])) {
            return;
        }

        $old = $this->tables[$from];
        $new = new Table($to);

        foreach ($old->columns() as $column) {
            $new->addColumn($column);
        }
        foreach ($old->foreignKeys() as $key) {
            $new->addForeignKey($key);
        }

        unset($this->tables[$from]);
        $this->tables[$to] = $new;
    }

    /** @return array<string, Table> */
    public function tables(): array
    {
        ksort($this->tables);

        return $this->tables;
    }

    public function isEmpty(): bool
    {
        return $this->tables === [];
    }
}
