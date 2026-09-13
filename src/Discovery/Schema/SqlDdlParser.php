<?php

declare(strict_types=1);

namespace PrivacyCI\Discovery\Schema;

/**
 * Parses raw CREATE TABLE statements out of migrations.
 *
 * Plenty of long-lived applications never used the Blueprint builder, their
 * schema arrived as a dump wrapped in DB::statement(). Those tables are
 * completely invisible to a scanner that only understands Schema::create(),
 * and the table it misses is usually the oldest and most important one.
 *
 * This is not a general SQL parser. It reads column and key definitions well
 * enough to build a data map, and ignores everything else.
 */
final class SqlDdlParser
{
    /** @return list<array{table: string, columns: list<array{name: string, type: string, nullable: bool}>, foreignKeys: list<array{column: string, table: string, references: string}>}> */
    public function createStatements(string $sql): array
    {
        $out = [];
        $offset = 0;

        while (($match = $this->nextCreate($sql, $offset)) !== null) {
            [$table, $body, $offset] = $match;

            $columns = [];
            $foreignKeys = [];

            foreach ($this->splitTopLevel($body) as $definition) {
                $definition = trim($definition);

                if ($definition === '') {
                    continue;
                }

                if (($key = $this->foreignKey($definition)) !== null) {
                    $foreignKeys[] = $key;

                    continue;
                }

                if ($this->isConstraint($definition)) {
                    continue;
                }

                if (($column = $this->column($definition)) !== null) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $out[] = ['table' => $table, 'columns' => $columns, 'foreignKeys' => $foreignKeys];
            }
        }

        return $out;
    }

    /** @return list<string> Tables named by DROP TABLE statements. */
    public function droppedTables(string $sql): array
    {
        preg_match_all(
            '/\bDROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?[`"\[]?([A-Za-z0-9_]+)[`"\]]?/i',
            $sql,
            $matches,
        );

        return array_values($matches[1]);
    }

    /**
     * @return array{0: string, 1: string, 2: int}|null  table, body, next offset
     */
    private function nextCreate(string $sql, int $offset): ?array
    {
        $pattern = '/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"\[]?([A-Za-z0-9_.]+)[`"\]]?\s*\(/i';

        if (preg_match($pattern, $sql, $m, PREG_OFFSET_CAPTURE, $offset) !== 1) {
            return null;
        }

        $open = $m[0][1] + strlen($m[0][0]) - 1;
        $close = $this->matchingParen($sql, $open);

        if ($close === null) {
            return null;
        }

        $table = $m[1][0];

        // `schema`.`table`, keep only the table.
        if (($dot = strrpos($table, '.')) !== false) {
            $table = substr($table, $dot + 1);
        }

        return [$table, substr($sql, $open + 1, $close - $open - 1), $close];
    }

    private function matchingParen(string $sql, int $open): ?int
    {
        $depth = 0;
        $length = strlen($sql);
        $quote = null;

        for ($i = $open; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;

                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Splits on commas that are not inside parentheses or quotes.
     *
     * enum('Active','Pending Delete') contains commas that do not separate
     * definitions; splitting naively would shred the column list.
     *
     * @return list<string>
     */
    private function splitTopLevel(string $body): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($quote !== null) {
                $current .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $body[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;

                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    private function isConstraint(string $definition): bool
    {
        return preg_match(
            '/^(PRIMARY\s+KEY|UNIQUE(\s+(KEY|INDEX))?|KEY|INDEX|FULLTEXT|SPATIAL|CONSTRAINT|CHECK|FOREIGN\s+KEY)\b/i',
            $definition,
        ) === 1;
    }

    /** @return array{column: string, table: string, references: string}|null */
    private function foreignKey(string $definition): ?array
    {
        $pattern = '/FOREIGN\s+KEY\s*\(\s*[`"]?([A-Za-z0-9_]+)[`"]?\s*\)\s*'
            .'REFERENCES\s+[`"]?([A-Za-z0-9_]+)[`"]?\s*\(\s*[`"]?([A-Za-z0-9_]+)[`"]?\s*\)/i';

        if (preg_match($pattern, $definition, $m) !== 1) {
            return null;
        }

        return ['column' => $m[1], 'table' => $m[2], 'references' => $m[3]];
    }

    /** @return array{name: string, type: string, nullable: bool}|null */
    private function column(string $definition): ?array
    {
        if (preg_match('/^[`"\[]?([A-Za-z0-9_]+)[`"\]]?\s+([A-Za-z]+)/', trim($definition), $m) !== 1) {
            return null;
        }

        return [
            'name' => $m[1],
            'type' => strtolower($m[2]),
            'nullable' => preg_match('/\bNOT\s+NULL\b/i', $definition) !== 1,
        ];
    }
}
