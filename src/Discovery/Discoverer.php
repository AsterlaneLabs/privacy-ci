<?php

declare(strict_types=1);

namespace PrivacyCI\Discovery;

use PrivacyCI\Discovery\Flow\FlowFinding;
use PrivacyCI\Discovery\Heuristics\ColumnHeuristics;
use PrivacyCI\Discovery\Models\ModelMap;
use PrivacyCI\Discovery\Scanners\IntegrationScanner;
use PrivacyCI\Discovery\Scanners\MigrationScanner;
use PrivacyCI\Discovery\Scanners\ModelScanner;
use PrivacyCI\Discovery\Scanners\StaticFlowScanner;
use PrivacyCI\Discovery\Schema\SchemaMap;
use PrivacyCI\Manifest\Classification;
use PrivacyCI\Manifest\Linkage;
use PrivacyCI\Manifest\Location;
use PrivacyCI\Manifest\LocationKind;
use PrivacyCI\Manifest\Manifest;
use PrivacyCI\Manifest\Subject;

/**
 * Composes the scanners into a findings manifest.
 *
 * Runs entirely from source: migrations, config and composer.lock. No database
 * connection, no credentials and no data access. That is the default path, and
 * the reason this can run in CI against a bare checkout.
 */
final class Discoverer
{
    public function __construct(
        private readonly MigrationScanner $migrations = new MigrationScanner,
        private readonly IntegrationScanner $integrations = new IntegrationScanner,
        private readonly ColumnHeuristics $heuristics = new ColumnHeuristics,
        private readonly ModelScanner $models = new ModelScanner,
        private readonly StaticFlowScanner $flows = new StaticFlowScanner,
        /** Findings below this are not worth a developer's attention. */
        private readonly float $minConfidence = 0.25,
    ) {
    }

    /**
     * @param  list<string>  $migrationPaths
     * @param  list<string>  $configPaths
     * @param  list<string>  $modelPaths
     * @param  list<string>  $sourcePaths  Application code to scan for data flows (Stage B).
     * @param  ModelMap|null $models       Pre-scanned models. Supplying them avoids a second
     *                                     scan and lets the caller report what was found.
     * @param  (callable(string): void)|null $onIssue  Receives anything that makes the
     *                                     result untrustworthy rather than merely empty.
     */
    public function discover(
        string $project,
        array $migrationPaths,
        array $configPaths = [],
        ?string $composerLock = null,
        Subject $subject = new Subject('user', 'users.id'),
        ?string $environment = null,
        ?string $commit = null,
        array $modelPaths = [],
        array $sourcePaths = [],
        ?ModelMap $models = null,
        ?callable $onIssue = null,
    ): Manifest {
        $schema = $this->migrations->scan($migrationPaths);

        $this->checkSubjectRoot($schema, $subject, $onIssue);
        $models ??= $modelPaths === [] ? new ModelMap : $this->models->scan($modelPaths);

        $graph = new ForeignKeyGraph($schema);
        $reachable = $graph->reachableFrom($subject->rootTable());
        $declared = $this->declaredLinks($models, $subject);

        $locations = [];
        $seen = [];

        foreach ($schema->tables() as $tableName => $table) {
            $isRoot = $tableName === $subject->rootTable();
            $link = $reachable[$tableName] ?? null;
            $model = $models->forTable($tableName);

            foreach ($table->columns() as $column) {
                $location = $this->classifyColumn(
                    $tableName,
                    $column->name,
                    $column->definedIn,
                    $subject,
                    $isRoot,
                    $link,
                    $declared["{$tableName}.{$column->name}"] ?? null,
                    $model?->looksSensitive($column->name) ?? false,
                );

                if ($location !== null) {
                    $locations[] = $location;
                    $seen[$location->path] = true;
                }
            }
        }

        // Stage B: keys and paths that look like they embed the subject's id.
        // Appended rather than merged, because these are locations the schema
        // scanners cannot see at all. Redis keys and object-storage paths.
        foreach ($this->flowLocations($sourcePaths, $subject) as $location) {
            if (! isset($seen[$location->path])) {
                $locations[] = $location;
                $seen[$location->path] = true;
            }
        }

        // A relationship can name a table with no migration in this repository:
        // a legacy table, or one owned by another service. The association is
        // still real and still deterministic, so it must not vanish from the map.
        foreach ($declared as $path => $evidence) {
            if (! isset($seen[$path])) {
                $locations[] = $this->location($path, Linkage::Relationship, 1.0, $evidence, $subject);
            }
        }

        return new Manifest(
            project: $project,
            subjects: [$subject],
            locations: $locations,
            integrations: $configPaths === [] && $composerLock === null
                ? []
                : $this->integrations->scan($configPaths, $composerLock),
            environment: $environment,
            commit: $commit,
            scannedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    /**
     * A subject root that does not exist makes the whole map meaningless.
     *
     * Nothing links to a table that was never found, so the graph is empty and
     * the report shrinks to a handful of name matches. That looks like a clean
     * application rather than a misconfigured scan, which is the worse of the
     * two failures.
     *
     * @param  (callable(string): void)|null  $onIssue
     */
    private function checkSubjectRoot(SchemaMap $schema, Subject $subject, ?callable $onIssue): void
    {
        if ($onIssue === null || $schema->isEmpty()) {
            return;
        }

        $table = $subject->rootTable();

        if (! $schema->hasTable($table)) {
            $names = array_keys($schema->tables());
            sort($names);

            $onIssue(sprintf(
                'Subject root table "%s" is not in the discovered schema, so nothing can link '
                .'to it and the map is incomplete. Set privacy.subjects to a table that exists. '
                .'Found %d table(s): %s%s',
                $table,
                count($names),
                implode(', ', array_slice($names, 0, 8)),
                count($names) > 8 ? ', ...' : '',
            ));

            return;
        }

        if (! $schema->table($table)->hasColumn($subject->rootColumn())) {
            $columns = array_keys($schema->table($table)->columns());

            $onIssue(sprintf(
                'Subject root column "%s.%s" does not exist. Columns on %s: %s',
                $table,
                $subject->rootColumn(),
                $table,
                implode(', ', array_slice($columns, 0, 10)),
            ));
        }
    }

    /**
     * @param  list<string>  $sourcePaths
     * @return list<Location>
     */
    private function flowLocations(array $sourcePaths, Subject $subject): array
    {
        if ($sourcePaths === []) {
            return [];
        }

        return array_map(
            static fn (FlowFinding $f): Location => new Location(
                id: Location::idFor($f->kind, $f->store, $f->pattern),
                kind: $f->kind,
                store: $f->store,
                path: $f->pattern,
                subject: $subject->type,
                linkage: Linkage::Inferred,
                confidence: $f->confidence,
                classification: Classification::Unclassified,
                evidence: $f->evidence,
            ),
            $this->flows->scan($sourcePaths, $subject),
        );
    }

    /**
     * Columns revealed by a declared Eloquent relationship rather than a foreign key.
     *
     * This is the model scanner earning its place: plenty of production schemas
     * declare belongsTo(User::class) with no matching database constraint. The
     * link is real and deterministic, and the migration scanner cannot see it.
     *
     * @return array<string, list<string>> path => evidence
     */
    private function declaredLinks(ModelMap $models, Subject $subject): array
    {
        $subjectModel = $models->forTable($subject->rootTable());
        $links = [];

        foreach ($models->all() as $model) {
            foreach ($model->relations as $relation) {
                if (! $relation->isSupported()) {
                    continue;
                }

                $related = $models->forClass($relation->relatedClass);

                if ($relation->keyIsLocal()) {
                    // belongsTo: the key is on this model's table, pointing outward.
                    if ($related === null || $related->table !== $subject->rootTable()) {
                        continue;
                    }

                    $column = $relation->foreignKey ?? $this->conventionKey($related->table);
                    $links["{$model->table}.{$column}"] = [
                        "{$model->shortName()}::{$relation->method}() declares "
                        ."{$relation->type}({$related->shortName()}::class)",
                        'no database foreign key required',
                    ];

                    continue;
                }

                // hasMany/hasOne: the key lives on the *related* table, pointing back here.
                if ($related === null || $subjectModel === null || $model->table !== $subject->rootTable()) {
                    continue;
                }

                $column = $relation->foreignKey ?? $this->conventionKey($model->table);
                $links["{$related->table}.{$column}"] = [
                    "{$model->shortName()}::{$relation->method}() declares "
                    ."{$relation->type}({$related->shortName()}::class)",
                    'no database foreign key required',
                ];
            }
        }

        return $links;
    }

    /** Eloquent's default foreign key: users => user_id. */
    private function conventionKey(string $table): string
    {
        return rtrim(preg_replace('/(ies)$/', 'y', $table) ?? $table, 's').'_id';
    }

    /**
     * @param  array{column: string, path: list<string>, hops: int}|null  $link
     * @param  list<string>|null  $declared
     */
    private function classifyColumn(
        string $table,
        string $column,
        ?string $definedIn,
        Subject $subject,
        bool $isRoot,
        ?array $link,
        ?array $declared = null,
        bool $sensitive = false,
    ): ?Location {
        $path = "{$table}.{$column}";
        $heuristic = $this->heuristics->score($column);
        $evidence = [];

        // The foreign key itself: deterministic, and the strongest signal we have.
        if ($link !== null && $link['column'] === $column) {
            $evidence = $link['path'];

            return $this->location(
                $path,
                Linkage::ForeignKey,
                1.0,
                $evidence,
                $subject,
            );
        }

        // Declared by a relationship but not by a constraint.
        if ($declared !== null) {
            return $this->location($path, Linkage::Relationship, 1.0, $declared, $subject);
        }

        // The subject's own identifying column.
        if ($isRoot && $column === $subject->rootColumn()) {
            return $this->location(
                $path,
                Linkage::SubjectRoot,
                1.0,
                ["subject root for '{$subject->type}'"],
                $subject,
            );
        }

        if ($heuristic === null) {
            return null;
        }

        $confidence = $heuristic['confidence'];
        $linked = $isRoot || $link !== null;

        // A generic word is only personal in context. On a lookup table of
        // countries, `name` is "Germany"; on the subject table it is a person.
        // With no route from the subject, damp it below the reporting threshold
        // rather than dropping it, a lower --min-confidence still surfaces it.
        if (! $linked && $heuristic['tier'] === ColumnHeuristics::GENERIC) {
            $confidence *= 0.4;
            $evidence[] = 'no route from the subject to this table';
        }

        // A personal-looking column on a table already linked to the subject is
        // more likely to be personal than the same column on an unrelated table.
        if ($isRoot || $link !== null) {
            // Capped below 1.0: a confidence of exactly 1.0 means
            // "deterministic", and a name match is never that however good it looks.
            $confidence = min(0.99, $confidence + 0.1);
            $evidence[] = $isRoot
                ? 'on the subject root table'
                : "reachable from {$subject->rootTable()} in {$link['hops']} hop(s)";
        }

        $evidence[] = "column name matches {$heuristic['label']}";

        if ($sensitive) {
            $confidence = min(0.99, $confidence + 0.15);
            $evidence[] = 'marked $hidden or encrypted by its model';
        }

        if ($definedIn !== null) {
            $evidence[] = "defined in {$definedIn}";
        }

        if ($confidence < $this->minConfidence) {
            return null;
        }

        return $this->location($path, Linkage::Heuristic, $confidence, $evidence, $subject);
    }

    /** @param list<string> $evidence */
    private function location(
        string $path,
        Linkage $linkage,
        float $confidence,
        array $evidence,
        Subject $subject,
    ): Location {
        return new Location(
            id: Location::idFor(LocationKind::DatabaseColumn, 'primary', $path),
            kind: LocationKind::DatabaseColumn,
            store: 'primary',
            path: $path,
            subject: $subject->type,
            linkage: $linkage,
            confidence: $confidence,
            classification: Classification::Unclassified,
            evidence: array_values($evidence),
        );
    }
}
