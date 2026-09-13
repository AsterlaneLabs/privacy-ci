<?php

declare(strict_types=1);

namespace PrivacyCI\Console;

use Illuminate\Console\Command;
use PrivacyCI\Console\Concerns\ResolvesManifest;
use PrivacyCI\Console\Concerns\WritesReports;
use PrivacyCI\Discovery\Discoverer;
use PrivacyCI\Discovery\Models\ModelMap;
use PrivacyCI\Generation\PolicyGenerator;
use PrivacyCI\Generation\NamespacePath;
use PrivacyCI\Policy\InvalidPolicy;

/**
 * `php artisan privacy:make-policy`
 *
 * Turns a wall of findings into a file you edit rather than one you compose
 * from scratch. Every rule arrives commented out. See PolicyGenerator.
 */
final class MakePolicyCommand extends Command
{
    use ResolvesManifest;
    use WritesReports;

    protected $signature = 'privacy:make-policy
        {--subject= : Which configured subject to trace}
        {--class= : Class name for the policy}
        {--namespace=App\\Privacy : Namespace for the policy}
        {--path= : Where to write the file}
        {--only-blocking : Scaffold only the findings that fail CI today}
        {--print : Write to stdout instead of a file}
        {--force : Overwrite an existing policy}';

    protected $description = 'Scaffold a privacy policy from what discovery found';

    public function handle(Discoverer $discoverer): int
    {
        try {
            $manifest = $this->resolveManifest($discoverer, $this->option('subject'));
        } catch (InvalidPolicy $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $subject = $this->resolveSubject($this->option('subject'));

        if ($manifest === null || $subject === null) {
            return self::FAILURE;
        }

        $models = $this->scanModels($this->existingPaths('privacy.discovery.model_paths'));
        $class = (string) ($this->option('class') ?: ucfirst($subject->type).'PrivacyPolicy');

        $code = (new PolicyGenerator)->generate(
            $manifest,
            $subject,
            $models,
            (string) $this->option('namespace'),
            $class,
            (bool) $this->option('only-blocking'),
        );

        if ($this->option('print')) {
            // Through the raw stream: SymfonyStyle normalises whitespace, which
            // turns generated code into something that will not parse.
            $this->writeVerbatim(rtrim($code));

            return self::SUCCESS;
        }

        $path = $this->targetPath($class);

        if (is_file($path) && ! $this->option('force')) {
            // A policy is a record of decisions. Overwriting one silently would
            // discard reasoning nobody can reconstruct from the schema.
            $this->components->error("{$path} already exists. Pass --force to replace it.");

            return self::FAILURE;
        }

        if (! is_dir($directory = dirname($path))) {
            mkdir($directory, 0o755, true);
        }

        if (file_put_contents($path, $code) === false) {
            $this->components->error("Could not write {$path}.");

            return self::FAILURE;
        }

        $unclassified = count($manifest->unclassified());

        $this->components->info("Policy scaffolded to {$path}");
        $this->components->warn(sprintf(
            '%d finding(s) written as commented suggestions. Nothing is classified '
            .'until you uncomment it.',
            $unclassified,
        ));
        $this->components->twoColumnDetail(
            'Next',
            sprintf('Edit it, register \\%s\\%s::class in config/privacy.php, then run privacy:check.',
                trim((string) $this->option('namespace'), '\\'), $class),
        );

        return self::SUCCESS;
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
