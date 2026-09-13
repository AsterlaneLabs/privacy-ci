<?php

declare(strict_types=1);

namespace PrivacyCI\Console;

use Illuminate\Console\Command;
use PrivacyCI\Baseline\Baseline;
use PrivacyCI\Check\PolicyCheck;
use PrivacyCI\Console\Concerns\ResolvesManifest;
use PrivacyCI\Console\Concerns\WritesReports;
use PrivacyCI\Discovery\Discoverer;
use PrivacyCI\Policy\InvalidPolicy;
use PrivacyCI\Reporting\CheckReport;

/**
 * `php artisan privacy:check`, the CI gate.
 *
 * Fails only on user-linked storage that is deterministic, unclassified, and
 * new relative to the committed baseline. Everything else is reported and
 * nothing else can break a build.
 */
final class CheckCommand extends Command
{
    use ResolvesManifest;
    use WritesReports;

    protected $signature = 'privacy:check
        {--subject= : Which configured subject to trace}
        {--baseline= : Path to the baseline file}
        {--warn-only : Report violations but always exit successfully}
        {--all : List previously reviewed findings instead of counting them}
        {--json : Emit the result as JSON}';

    protected $description = 'Fail when new user-linked storage has no privacy policy';

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

        $baseline = Baseline::load($this->baselinePath());
        $result = (new PolicyCheck)->run(
            $manifest,
            // --all makes the check behave as though nothing had been reviewed.
            $this->option('all') ? Baseline::empty() : $baseline,
        );

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'passed' => $result->passes(),
                'violations' => array_map(static fn ($l): array => $l->toArray(), $result->violations),
                'warnings' => array_map(static fn ($l): array => $l->toArray(), $result->warnings),
                'grandfathered' => count($result->grandfathered),
                'stale_baseline_entries' => count($result->stale),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->writeReport((new CheckReport($this->reportsAreDecorated()))->render($result));
        }

        if ($result->passes()) {
            return self::SUCCESS;
        }

        // --warn-only exists for gradual adoption: a team can see the check
        // working for a sprint before it is allowed to block anyone.
        return $this->option('warn-only') || ! config('privacy.ci.fail_on_new', true)
            ? self::SUCCESS
            : self::FAILURE;
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
