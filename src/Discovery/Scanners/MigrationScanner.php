<?php

declare(strict_types=1);

namespace PrivacyCI\Discovery\Scanners;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PrivacyCI\Discovery\Schema\Column;
use PrivacyCI\Discovery\Schema\ForeignKey;
use PrivacyCI\Discovery\Schema\SchemaMap;
use PrivacyCI\Discovery\Schema\SqlDdlParser;

/**
 * Reconstructs the schema by replaying migration files in order.
 *
 * Migrations are a history, not a snapshot: a column added in 2021 and dropped in
 * 2023 must not appear in the result. So we apply operations chronologically
 * (filename order, which Laravel timestamps guarantee) rather than unioning them.
 *
 * Requires no database connection.
 */
final class MigrationScanner
{
    /** Methods whose first string argument names a new column. */
    private const COLUMN_METHODS = [
        'bigIncrements', 'bigInteger', 'binary', 'boolean', 'char', 'date', 'dateTime',
        'dateTimeTz', 'decimal', 'double', 'enum', 'float', 'foreignId', 'foreignUlid',
        'foreignUuid', 'geometry', 'increments', 'integer', 'ipAddress', 'json', 'jsonb',
        'lineString', 'longText', 'macAddress', 'mediumIncrements', 'mediumInteger',
        'mediumText', 'multiLineString', 'multiPoint', 'multiPolygon', 'point', 'polygon',
        'set', 'smallIncrements', 'smallInteger', 'string', 'text', 'time', 'timeTz',
        'timestamp', 'timestampTz', 'tinyInteger', 'tinyText', 'ulid', 'unsignedBigInteger',
        'unsignedDecimal', 'unsignedInteger', 'unsignedMediumInteger', 'unsignedSmallInteger',
        'unsignedTinyInteger', 'uuid', 'year',
    ];

    /** Zero-argument helpers that create well-known columns. */
    private const IMPLICIT_COLUMNS = [
        'id' => [['id', 'bigint']],
        'uuid' => [['uuid', 'uuid']],
        'timestamps' => [['created_at', 'timestamp'], ['updated_at', 'timestamp']],
        'timestampsTz' => [['created_at', 'timestamp'], ['updated_at', 'timestamp']],
        'nullableTimestamps' => [['created_at', 'timestamp'], ['updated_at', 'timestamp']],
        'softDeletes' => [['deleted_at', 'timestamp']],
        'softDeletesTz' => [['deleted_at', 'timestamp']],
        'rememberToken' => [['remember_token', 'string']],
    ];

    private readonly Parser $parser;

    private readonly NodeFinder $finder;

    private readonly SqlDdlParser $ddl;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->finder = new NodeFinder;
        $this->ddl = new SqlDdlParser;
    }

    /**
     * @param  list<string>  $paths  Migration directories to scan.
     */
    public function scan(array $paths): SchemaMap
    {
        $schema = new SchemaMap;

        foreach ($this->migrationFiles($paths) as $file) {
            $this->applyFile($file, $schema);
        }

        return $schema;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>  Absolute paths, chronologically ordered.
     */
    private function migrationFiles(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            foreach ((array) glob(rtrim($path, '/').'/*.php') as $file) {
                if (is_string($file)) {
                    $files[] = $file;
                }
            }
        }

        // Laravel's timestamp prefix makes basename order chronological.
        usort($files, static fn (string $a, string $b): int => basename($a) <=> basename($b));

        return $files;
    }

    private function applyFile(string $file, SchemaMap $schema): void
    {
        $code = @file_get_contents($file);

        if ($code === false) {
            return;
        }

        try {
            $ast = $this->parser->parse($code);
        } catch (\Throwable) {
            // A migration we cannot parse is skipped rather than fatal: one bad file
            // must never take down discovery for the whole application.
            return;
        }

        if ($ast === null) {
            return;
        }

        foreach ($this->schemaCalls($ast) as $call) {
            $this->applySchemaCall($call, $schema, basename($file));
        }
    }

    /**
     * Schema calls from up() only.
     *
     * Reading the whole file would apply down() as well, and since down() drops
     * exactly what up() created, every table would be created and then deleted,
     * leaving an empty schema and no findings at all.
     *
     * @param  list<Node>  $ast
     * @return list<StaticCall>
     */
    private function schemaCalls(array $ast): array
    {
        /** @var list<Node\Stmt\ClassMethod> $methods */
        $methods = $this->finder->find($ast, static fn (Node $n): bool => $n instanceof Node\Stmt\ClassMethod
            && $n->name->toString() === 'up');

        // A migration with no up() is unusual but not impossible; scanning the
        // whole file is a better fallback than ignoring it.
        $scope = $methods === [] ? $ast : array_merge(...array_map(
            static fn (Node\Stmt\ClassMethod $m): array => (array) $m->stmts,
            $methods,
        ));

        /** @var list<StaticCall> */
        return $this->finder->find($scope, static fn (Node $n): bool => $n instanceof StaticCall
            && $n->class instanceof Node\Name
            && in_array($n->class->getLast(), ['Schema', 'DB'], true));
    }

    private function applySchemaCall(StaticCall $call, SchemaMap $schema, string $origin): void
    {
        if (! $call->name instanceof Node\Identifier) {
            return;
        }

        $method = $call->name->toString();
        $args = $call->getArgs();

        $first = isset($args[0]) ? $this->stringValue($args[0]->value) : null;

        match ($method) {
            'create', 'table' => $first !== null
                ? $this->applyBlueprint($first, $args[1] ?? null, $schema, $origin)
                : null,
            // Raw DDL. Long-lived applications often never used the Blueprint
            // builder at all, and the table they define this way is usually the
            // oldest and most important one.
            'statement', 'unprepared' => $first !== null
                ? $this->applyRawSql($first, $schema, $origin)
                : null,
            'drop', 'dropIfExists' => $first !== null ? $schema->dropTable($first) : null,
            'rename' => ($first !== null && isset($args[1]))
                && ($to = $this->stringValue($args[1]->value)) !== null
                    ? $schema->renameTable($first, $to)
                    : null,
            default => null,
        };
    }

    private function applyRawSql(string $sql, SchemaMap $schema, string $origin): void
    {
        foreach ($this->ddl->createStatements($sql) as $statement) {
            $table = $schema->table($statement['table']);

            foreach ($statement['columns'] as $column) {
                $table->addColumn(new Column(
                    $column['name'],
                    $column['type'],
                    $column['nullable'],
                    $origin,
                ));
            }

            foreach ($statement['foreignKeys'] as $key) {
                $table->addForeignKey(new ForeignKey(
                    $key['column'],
                    $key['table'],
                    $key['references'],
                    $origin,
                ));
            }
        }

        foreach ($this->ddl->droppedTables($sql) as $dropped) {
            $schema->dropTable($dropped);
        }
    }

    private function applyBlueprint(
        string $tableName,
        ?Node\Arg $closureArg,
        SchemaMap $schema,
        string $origin,
    ): void {
        $table = $schema->table($tableName);

        if ($closureArg === null) {
            return;
        }

        $closure = $closureArg->value;

        if (! $closure instanceof Node\Expr\Closure && ! $closure instanceof Node\Expr\ArrowFunction) {
            return;
        }

        // Walk statements, not every MethodCall node. A chain like
        // $table->string('x')->nullable() contains two MethodCall nodes, and
        // visiting both would apply the column twice, the second time without
        // its modifiers, silently losing `nullable`.
        /** @var list<Node\Stmt\Expression> $statements */
        $statements = $this->finder->findInstanceOf(
            (array) $closure->getStmts(),
            Node\Stmt\Expression::class,
        );

        foreach ($statements as $statement) {
            if (! $statement->expr instanceof MethodCall) {
                continue;
            }

            $chain = $this->unwrapChain($statement->expr);

            if ($chain === null) {
                continue;
            }

            $this->applyBlueprintChain($chain, $table, $tableName, $origin);
        }
    }

    /**
     * Flattens $table->a(...)->b(...)->c(...) into an ordered list, innermost first.
     * Returns null when the chain does not root at a variable (i.e. not a column definition).
     *
     * @return list<MethodCall>|null
     */
    private function unwrapChain(MethodCall $call): ?array
    {
        $chain = [];
        $node = $call;

        while ($node instanceof MethodCall) {
            array_unshift($chain, $node);
            $node = $node->var;
        }

        if (! $node instanceof Node\Expr\Variable) {
            return null;
        }

        return $chain;
    }

    /** @param list<MethodCall> $chain */
    private function applyBlueprintChain(
        array $chain,
        \PrivacyCI\Discovery\Schema\Table $table,
        string $tableName,
        string $origin,
    ): void {
        $root = $chain[0];

        if (! $root->name instanceof Node\Identifier) {
            return;
        }

        $method = $root->name->toString();
        $args = $root->getArgs();
        $modifiers = array_slice($chain, 1);

        // Dropping columns.
        if (in_array($method, ['dropColumn', 'dropForeign', 'dropIfExists'], true)) {
            if ($method === 'dropColumn') {
                foreach ($this->stringList($args[0]->value ?? null) as $name) {
                    $table->dropColumn($name);
                }
            }

            return;
        }

        if ($method === 'renameColumn' && isset($args[0], $args[1])) {
            $from = $this->stringValue($args[0]->value);
            $to = $this->stringValue($args[1]->value);

            if ($from !== null && $to !== null && ($existing = $table->column($from)) !== null) {
                $table->dropColumn($from);
                $table->addColumn(new Column($to, $existing->type, $existing->nullable, $origin));
            }

            return;
        }

        // Zero-argument helpers: timestamps(), softDeletes(), rememberToken().
        if (isset(self::IMPLICIT_COLUMNS[$method]) && $args === []) {
            foreach (self::IMPLICIT_COLUMNS[$method] as [$name, $type]) {
                $table->addColumn(new Column($name, $type, false, $origin));
            }

            return;
        }

        // morphs('commentable') => commentable_id + commentable_type
        if (in_array($method, ['morphs', 'nullableMorphs', 'uuidMorphs', 'ulidMorphs'], true)) {
            $name = isset($args[0]) ? $this->stringValue($args[0]->value) : null;

            if ($name !== null) {
                $nullable = str_starts_with($method, 'nullable');
                $table->addColumn(new Column($name.'_id', 'bigint', $nullable, $origin));
                $table->addColumn(new Column($name.'_type', 'string', $nullable, $origin));
            }

            return;
        }

        // foreign('user_id')->references('id')->on('users')
        if ($method === 'foreign') {
            $this->applyExplicitForeignKey($args, $modifiers, $table, $origin);

            return;
        }

        if (! in_array($method, self::COLUMN_METHODS, true)) {
            return;
        }

        $name = isset($args[0]) ? $this->stringValue($args[0]->value) : null;

        if ($name === null) {
            // id() with no args, and the uuid()/ulid() zero-arg forms.
            if (isset(self::IMPLICIT_COLUMNS[$method])) {
                foreach (self::IMPLICIT_COLUMNS[$method] as [$implied, $type]) {
                    $table->addColumn(new Column($implied, $type, false, $origin));
                }
            }

            return;
        }

        $nullable = $this->chainHas($modifiers, 'nullable');
        $table->addColumn(new Column($name, $method, $nullable, $origin));

        // foreignId('user_id') implies users.id by convention; constrained() confirms
        // it and may override the target table.
        if (in_array($method, ['foreignId', 'foreignUuid', 'foreignUlid'], true)) {
            $target = $this->constrainedTarget($modifiers) ?? $this->conventionTable($name);

            if ($target !== null) {
                $table->addForeignKey(new ForeignKey($name, $target, 'id', $origin));
            }
        }
    }

    /**
     * @param  list<Node\Arg>      $args
     * @param  list<MethodCall>    $modifiers
     */
    private function applyExplicitForeignKey(
        array $args,
        array $modifiers,
        \PrivacyCI\Discovery\Schema\Table $table,
        string $origin,
    ): void {
        $column = isset($args[0]) ? $this->stringValue($args[0]->value) : null;

        if ($column === null) {
            return;
        }

        $references = 'id';
        $on = null;

        foreach ($modifiers as $modifier) {
            if (! $modifier->name instanceof Node\Identifier) {
                continue;
            }

            $modArgs = $modifier->getArgs();
            $value = isset($modArgs[0]) ? $this->stringValue($modArgs[0]->value) : null;

            match ($modifier->name->toString()) {
                'references' => $references = $value ?? $references,
                'on' => $on = $value,
                default => null,
            };
        }

        if ($on !== null) {
            $table->addForeignKey(new ForeignKey($column, $on, $references, $origin));
        }
    }

    /** @param list<MethodCall> $modifiers */
    private function constrainedTarget(array $modifiers): ?string
    {
        foreach ($modifiers as $modifier) {
            if ($modifier->name instanceof Node\Identifier
                && $modifier->name->toString() === 'constrained') {
                $args = $modifier->getArgs();

                return isset($args[0]) ? $this->stringValue($args[0]->value) : null;
            }
        }

        return null;
    }

    /** @param list<MethodCall> $modifiers */
    private function chainHas(array $modifiers, string $name): bool
    {
        foreach ($modifiers as $modifier) {
            if ($modifier->name instanceof Node\Identifier && $modifier->name->toString() === $name) {
                return true;
            }
        }

        return false;
    }

    /** user_id => users, author_id => authors. Laravel's own convention. */
    private function conventionTable(string $column): ?string
    {
        if (! str_ends_with($column, '_id')) {
            return null;
        }

        $base = substr($column, 0, -3);

        return $base === '' ? null : Inflector::pluralize($base);
    }

    private function stringValue(?Node $node): ?string
    {
        return $node instanceof Node\Scalar\String_ ? $node->value : null;
    }

    /** @return list<string> */
    private function stringList(?Node $node): array
    {
        if ($node instanceof Node\Scalar\String_) {
            return [$node->value];
        }

        if (! $node instanceof Node\Expr\Array_) {
            return [];
        }

        $out = [];

        foreach ($node->items as $item) {
            if ($item !== null && ($value = $this->stringValue($item->value)) !== null) {
                $out[] = $value;
            }
        }

        return $out;
    }
}
