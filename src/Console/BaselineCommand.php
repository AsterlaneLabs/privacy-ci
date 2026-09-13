<?php

declare(strict_types=1);

namespace PrivacyCI\Console;

use Illuminate\Console\Command;
use PrivacyCI\Baseline\Baseline;
use PrivacyCI\Console\Concerns\ResolvesManifest;
use PrivacyCI\Discovery\Discoverer;
use PrivacyCI\Policy\InvalidPolicy;

/**
 * `php artisan privacy:baseline`, grandfather what already exists.
 *
 * Run once when adopting the tool. Everything unresolved today becomes
 * pre-existing debt that warns rather than fails, so the first CI run is a
 * report instead of three hundred broken builds.
 */
final class BaselineCommand extends Command
{
    use ResolvesManifest;

    protected $signature = 'privacy:baseline
        {--subject= : Which configured subject to trace}
        {--baseline= : Where to write the file}
        {--force : Overwrite an existing baseline without asking}';

    protected $description = 'Record existing findings so only new ones fail CI';

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

        $path = $this->baselinePath();
        $existing = Baseline::load($path);

        if (! $existing->isEmpty() && ! $this->option('force')) {
            $this->components->warn(sprintf(
                'A baseline already exists at %s with %d entr%s.',
                $path,
                $existing->count(),
                $existing->count() === 1 ? 'y' : 'ies',
            ));

            // Re-baselining is how a team accidentally forgives every violation
            // it was supposed to fix, so it never happens without being asked.
            if (! $this->confirm('Overwrite it? Any unfixed violations will be forgiven.', false)) {
                return self::SUCCESS;
            }
        }

        $baseline = Baseline::from($manifest, gmdate('Y-m-d\TH:i:s\Z'));

        if (file_put_contents($path, $baseline->toJson()) === false) {
            $this->components->error("Could not write {$path}.");

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Baselined %d finding%s to %s.',
            $baseline->count(),
            $baseline->count() === 1 ? '' : 's',
            $path,
        ));

        $this->components->twoColumnDetail(
            'Next',
            'Commit this file, then add privacy:check to CI.',
        );

        return self::SUCCESS;
    }

    private function baselinePath(): string
    {
        $option = $this->option('baseline');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        return (string) config('privacy.ci.baseline', base_path('privacy-baseline.json'));
    }
}
