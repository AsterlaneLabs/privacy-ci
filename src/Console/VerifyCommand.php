<?php

declare(strict_types=1);

namespace PrivacyCI\Console;

use Illuminate\Console\Command;
use PrivacyCI\Console\Concerns\ResolvesManifest;
use PrivacyCI\Console\Concerns\WritesReports;
use PrivacyCI\Discovery\Discoverer;
use PrivacyCI\Lifecycle\DeletionStore;
use PrivacyCI\Policy\InvalidPolicy;
use PrivacyCI\Reporting\VerificationReport;
use PrivacyCI\Verification\Footprint;
use PrivacyCI\Verification\FootprintResolver;
use PrivacyCI\Verification\Outcome;
use PrivacyCI\Verification\Verifier;

/**
 * `php artisan privacy:verify {id}`, did the erasure actually land?
 *
 * Prefers the footprint captured before deletion ran. Falling back to resolving
 * one now is weaker and says so: after deletion the subject's addresses can only
 * be derived from policy, so the check confirms policy coverage rather than
 * proving removal.
 */
final class VerifyCommand extends Command
{
    use ResolvesManifest;
    use WritesReports;

    protected $signature = 'privacy:verify
        {id : The subject identifier}
        {--subject= : Subject type}
        {--json : Emit the result as JSON}';

    protected $description = 'Check that a subject was actually erased everywhere';

    public function handle(Discoverer $discoverer, Verifier $verifier, DeletionStore $store): int
    {
        $id = (string) $this->argument('id');
        $subjectType = $this->option('subject');

        $footprint = $this->storedFootprint($store, $id, $subjectType);

        if ($footprint === null) {
            $this->components->warn(
                'No footprint was captured for this subject, so addresses are being '
                .'derived from policy now. That confirms policy coverage; it cannot '
                .'prove what was removed.',
            );

            try {
                $manifest = $this->resolveManifest($discoverer, $subjectType);
            } catch (InvalidPolicy $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $subject = $this->resolveSubject($subjectType);

            if ($manifest === null || $subject === null) {
                return self::FAILURE;
            }

            $footprint = (new FootprintResolver)->resolve($manifest, $subject, $id);
        }

        $result = $verifier->verify($footprint, gmdate('Y-m-d\TH:i:s\Z'));

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $result->toArray() + ['fingerprint' => $result->fingerprint()],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->writeReport((new VerificationReport($this->reportsAreDecorated()))->render($result));
        }

        if (! $result->passed()) {
            return self::FAILURE;
        }

        // Gaps are not failures, but they must not read as a clean bill of health.
        return $result->complete() ? self::SUCCESS : self::FAILURE;
    }

    private function storedFootprint(DeletionStore $store, string $id, ?string $subjectType): ?Footprint
    {
        foreach ($store->all() as $request) {
            if ($request->subjectId !== $id || $request->footprintJson === null) {
                continue;
            }

            if ($subjectType !== null && $subjectType !== '' && $request->subjectType !== $subjectType) {
                continue;
            }

            return Footprint::fromJson($request->footprintJson);
        }

        return null;
    }
}
