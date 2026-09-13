<?php

declare(strict_types=1);

namespace PrivacyCI\Console;

use Illuminate\Console\Command;
use PrivacyCI\Console\Concerns\ResolvesManifest;
use PrivacyCI\Console\Concerns\WritesReports;
use PrivacyCI\Discovery\Discoverer;
use PrivacyCI\Policy\InvalidPolicy;
use PrivacyCI\Reporting\ConsoleReport;

/**
 * `php artisan privacy:discover`
 *
 * Reports; never fails. Failing a build is privacy:check's job, and only for
 * locations new relative to the committed baseline.
 */
final class DiscoverCommand extends Command
{
    use ResolvesManifest;
    use WritesReports;

    protected $signature = 'privacy:discover
        {--subject= : Which configured subject to trace (default: the first)}
        {--json : Emit the findings manifest instead of a report}
        {--output= : Write the manifest to a file}';

    protected $description = 'Find where personal data lives in this application';

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

        if ($this->option('json')) {
            $this->line($manifest->toJson());

            return self::SUCCESS;
        }

        if (is_string($output = $this->option('output')) && $output !== '') {
            file_put_contents($output, $manifest->toJson().PHP_EOL);
            $this->components->info("Manifest written to {$output}");
        }

        $this->writeReport((new ConsoleReport($this->reportsAreDecorated()))->render($manifest));

        if ($manifest->blocking() !== []) {
            $this->components->warn(sprintf(
                '%d location(s) are certain to hold personal data but have no policy. '
                .'Run privacy:check to enforce this in CI.',
                count($manifest->blocking()),
            ));
        }

        return self::SUCCESS;
    }
}
