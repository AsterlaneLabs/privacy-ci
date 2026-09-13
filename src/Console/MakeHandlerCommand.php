<?php

declare(strict_types=1);

namespace PrivacyCI\Console;

use Illuminate\Console\Command;
use PrivacyCI\Console\Concerns\ResolvesManifest;
use PrivacyCI\Discovery\Discoverer;
use PrivacyCI\Discovery\ForeignKeyGraph;
use PrivacyCI\Discovery\Models\ModelMap;
use PrivacyCI\Discovery\Scanners\MigrationScanner;
use PrivacyCI\Generation\HandlerGenerator;
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
            $this->output->write($code);

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

        $this->components->twoColumnDetail(
            'Next',
            sprintf('Review it, then set privacy.lifecycle.deleter to \\%s\\%s::class.',
                trim((string) $this->option('namespace'), '\\'),
                $class),
        );

        return self::SUCCESS;
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
