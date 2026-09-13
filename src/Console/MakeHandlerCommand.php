<?php

declare(strict_types=1);

namespace PrivacyCI\Console;

use Illuminate\Console\Command;
use PrivacyCI\Console\Concerns\ResolvesManifest;
use PrivacyCI\Console\Concerns\WritesReports;
use PrivacyCI\Discovery\Discoverer;
use PrivacyCI\Discovery\ForeignKeyGraph;
use PrivacyCI\Discovery\Models\ModelMap;
use PrivacyCI\Discovery\Scanners\MigrationScanner;
use PrivacyCI\Manifest\Classification;
use PrivacyCI\Generation\HandlerGenerator;
use PrivacyCI\Generation\HandlerPlan;
use PrivacyCI\Generation\HandlerPlanner;
use PrivacyCI\Manifest\Subject;
use PrivacyCI\Generation\NamespacePath;
use PrivacyCI\Policy\InvalidPolicy;

/**
 * `php artisan privacy:make-handler`
 *
 * Writes the deletion handler the policy implies, into the application's own
 * repository, for review as a diff. We emit code, not effects, the middle rung
 * of the deletion ladder, where the tool takes no runtime risk at all.
 */
final class MakeHandlerCommand extends Command
{
    use ResolvesManifest;
    use WritesReports;

    protected $signature = 'privacy:make-handler
        {--subject= : Which configured subject to trace}
        {--class= : Class name for the handler}
        {--namespace=App\\Privacy : Namespace for the handler}
        {--path= : Where to write the file}
        {--print : Write to stdout instead of a file}
        {--force : Overwrite an existing handler}';

    protected $description = 'Generate a deletion handler from your privacy policy';

    public function handle(Discoverer $discoverer): int
    {
        try {
            $manifest = $this->resolveManifest($discoverer, $this->option('subject'));
        } catch (InvalidPolicy $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($manifest === null) {
            return self::FAILURE;
        }

        $subject = $this->resolveSubject($this->option('subject'));

        if ($subject === null) {
            return self::FAILURE;
        }

        $models = $this->scanModels($this->existingPaths('privacy.discovery.model_paths'));
        $plan = (new HandlerPlanner)->plan($manifest, $subject, $this->tableDepth($subject), $models);

        $class = (string) ($this->option('class') ?: 'Delete'.ucfirst($subject->type));

        $code = (new HandlerGenerator)->generate(
            $plan,
            (string) $this->option('namespace'),
            $class,
            $subject->type,
        );

        if ($this->option('print')) {
            // Through the raw stream: SymfonyStyle normalises whitespace, which
            // turns generated code into something that will not parse.
            $this->writeReport(rtrim($code));

            return self::SUCCESS;
        }

        $path = $this->targetPath($class);

        if (is_file($path) && ! $this->option('force')) {
            // Once a developer has edited the generated file it is theirs, and
            // silently overwriting their work would be the fastest way to lose
            // their trust in the generator.
            $this->components->error("{$path} already exists. Review your changes, then pass --force.");

            return self::FAILURE;
        }

        if (! is_dir($directory = dirname($path))) {
            mkdir($directory, 0o755, true);
        }

        if (file_put_contents($path, $code) === false) {
            $this->components->error("Could not write {$path}.");

            return self::FAILURE;
        }

        $this->components->info("Handler written to {$path}");
        $this->reportGaps($plan);
        $this->reportUnnullableAnonymisation($plan);
        $this->reportRetainedForeignKeys($plan);

        $this->components->twoColumnDetail(
            'Next',
            sprintf('Review it, then set privacy.lifecycle.deleter to \\%s\\%s::class.',
                trim((string) $this->option('namespace'), '\\'),
                $class),
        );

        return self::SUCCESS;
    }

    /**
     * Retaining rows that point at a row we are deleting cannot work either.
     *
     * The database refuses the parent delete while a child still references it,
     * so the erasure stops at the last step with everything else already gone.
     * Retaining the row while dropping the link is anonymisation, not retention.
     */
    private function reportRetainedForeignKeys(HandlerPlan $plan): void
    {
        $deleted = [];
        $retained = [];

        foreach ($plan->steps as $step) {
            if ($step->table === null) {
                continue;
            }

            if ($step->classification === Classification::Delete) {
                $deleted[$step->table] = true;
            }

            if ($step->classification === Classification::Retain && $step->foreignKey !== null) {
                $retained[$step->table] = $step->foreignKey;
            }
        }

        $paths = $this->existingPaths('privacy.discovery.migration_paths');

        if ($paths === [] || $retained === [] || $deleted === []) {
            return;
        }

        $schema = (new MigrationScanner)->scan($paths);
        $problems = [];

        foreach ($retained as $table => $column) {
            if (! $schema->hasTable($table)) {
                continue;
            }

            $key = $schema->table($table)->foreignKeyFor($column);

            if ($key !== null && isset($deleted[$key->referencesTable])) {
                $problems[] = sprintf('%s.%s -> %s', $table, $column, $key->referencesTable);
            }
        }

        if ($problems === []) {
            return;
        }

        $this->components->warn(sprintf(
            'These tables are retained but hold a foreign key to a row being deleted, so '
            .'the delete will be refused: %s. Anonymise the link instead of retaining it, '
            .'or make the key nullable with ON DELETE SET NULL.',
            implode(', ', $problems),
        ));
    }

    /**
     * Anonymising a NOT NULL column cannot work, and we know that before it runs.
     *
     * The migration already told us the column is not nullable. Letting the
     * handler find out instead means discovering it during a real erasure, with
     * some stores already cleared and the database untouched.
     */
    private function reportUnnullableAnonymisation(HandlerPlan $plan): void
    {
        $paths = $this->existingPaths('privacy.discovery.migration_paths');

        if ($paths === []) {
            return;
        }

        $schema = (new MigrationScanner)->scan($paths);
        $problems = [];

        foreach ($plan->steps as $step) {
            if ($step->table === null || $step->replacements === []) {
                continue;
            }

            foreach ($step->replacements as $column => $value) {
                if ($value !== null || ! $schema->hasTable($step->table)) {
                    continue;
                }

                $definition = $schema->table($step->table)->column((string) $column);

                if ($definition !== null && ! $definition->nullable) {
                    $problems[] = $step->table.'.'.$column;
                }
            }
        }

        if ($problems === []) {
            return;
        }

        $this->components->warn(sprintf(
            'Anonymising sets these to null, but the schema declares them NOT NULL, so the '
            .'erasure will fail: %s. Make the columns nullable, or delete the rows instead '
            .'of anonymising them.',
            implode(', ', $problems),
        ));
    }

    private function reportGaps(\PrivacyCI\Generation\HandlerPlan $plan): void
    {
        if ($plan->unresolved === []) {
            return;
        }

        $this->components->warn(sprintf(
            '%d location(s) have no policy and are left as TODOs in the handler.',
            count($plan->unresolved),
        ));
    }

    /** @return array<string, int> */
    private function tableDepth(Subject $subject): array
    {
        $paths = $this->existingPaths('privacy.discovery.migration_paths');

        if ($paths === []) {
            return [];
        }

        $schema = (new MigrationScanner)->scan($paths);
        $depth = [];

        foreach ((new ForeignKeyGraph($schema))->reachableFrom($subject->rootTable()) as $table => $link) {
            $depth[$table] = $link['hops'];
        }

        return $depth;
    }

    private function targetPath(string $class): string
    {
        $option = $this->option('path');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        $namespace = trim((string) $this->option('namespace'), '\\');

        // Resolved from the application's own PSR-4 map, so a namespace that is
        // not App\\ lands where its autoloader will actually find it.
        return NamespacePath::fromComposer(base_path('composer.json'))
            ->fileFor($namespace.'\\'.$class, app_path('Privacy'));
    }
}
