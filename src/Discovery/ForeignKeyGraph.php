<?php

declare(strict_types=1);

namespace PrivacyCI\Discovery;

use PrivacyCI\Discovery\Schema\SchemaMap;

/**
 * Finds every table reachable from the subject's root table by foreign key.
 *
 * Breadth-first so that the shortest path wins: comments.user_id is reported as
 * one hop from users rather than via some longer route, which makes the evidence
 * string useful to a human reading the report.
 */
final class ForeignKeyGraph
{
    public function __construct(private readonly SchemaMap $schema)
    {
    }

    /**
     * @return array<string, array{column: string, path: list<string>, hops: int}>
     *         Keyed by table name; the column is the one linking back toward the subject.
     */
    public function reachableFrom(string $rootTable): array
    {
        $found = [];
        $queue = [[$rootTable, []]];
        $seen = [$rootTable => true];

        while ($queue !== []) {
            /** @var array{0: string, 1: list<string>} $entry */
            $entry = array_shift($queue);
            [$table, $path] = $entry;

            foreach ($this->schema->tables() as $name => $candidate) {
                if (isset($seen[$name])) {
                    continue;
                }

                foreach ($candidate->foreignKeys() as $key) {
                    if ($key->referencesTable !== $table) {
                        continue;
                    }

                    $seen[$name] = true;
                    $newPath = [...$path, "{$name}.{$key->column} -> {$table}.{$key->referencesColumn}"];

                    $found[$name] = [
                        'column' => $key->column,
                        'path' => $newPath,
                        'hops' => count($newPath),
                    ];

                    $queue[] = [$name, $newPath];
                    break;
                }
            }
        }

        return $found;
    }
}
