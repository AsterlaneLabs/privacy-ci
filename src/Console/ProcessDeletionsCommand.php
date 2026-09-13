<?php

declare(strict_types=1);

namespace PrivacyCI\Console;

use Illuminate\Console\Command;
use PrivacyCI\Console\Concerns\ResolvesManifest;
use PrivacyCI\Discovery\Discoverer;
use PrivacyCI\Lifecycle\DeletionSchedule;
use PrivacyCI\Lifecycle\ErasureAuditor;
use PrivacyCI\Lifecycle\SubjectDeleter;
use PrivacyCI\Lifecycle\SubjectNotifier;
use PrivacyCI\Verification\Laravel\SnapshotAuditor;
use PrivacyCI\Verification\Verifier;

/**
 * `php artisan privacy:process-deletions`
 *
 * The scheduled half of the grace period. Run it daily; it erases only subjects
 * whose window has closed and who did not come back.
 */
final class ProcessDeletionsCommand extends Command
{
    use ResolvesManifest;

    protected $signature = 'privacy:process-deletions
        {--limit=100 : Maximum requests to process in one run}
        {--dry-run : Show what would be erased without erasing anything}
        {--skip-reminders : Erase without sending approaching-deadline reminders}
        {--no-verify : Erase without capturing or checking a footprint}';

    protected $description = 'Send deadline reminders, then erase subjects whose grace period has elapsed';

    public function handle(
        DeletionSchedule $schedule,
        SubjectDeleter $deleter,
        SubjectNotifier $notifier,
        Discoverer $discoverer,
        Verifier $verifier,
    ): int {
        $limit = max(1, (int) $this->option('limit'));

        // Reminders first: a "1 day left" notice must not go out on the same
        // tick that erases the account it refers to.
        $reminderFailures = $this->option('skip-reminders') || $this->option('dry-run')
            ? 0
            : $this->sendReminders($schedule, $notifier);

        $due = $schedule->due($limit);

        if ($due === []) {
            $this->components->info('Nothing due for erasure.');

            return $reminderFailures > 0 ? self::FAILURE : self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->warn(sprintf('%d request(s) due. Dry run, nothing erased.', count($due)));

            $this->table(
                ['Subject', 'ID', 'Requested', 'Due since'],
                array_map(static fn ($r): array => [
                    $r->subjectType,
                    $r->subjectId,
                    $r->requestedAt->format('Y-m-d'),
                    $r->executeAfter->format('Y-m-d'),
                ], $due),
            );

            return self::SUCCESS;
        }

        $result = $schedule->process($deleter, $limit, $this->auditor($discoverer, $verifier));

        if ($result['completed'] > 0) {
            $this->components->info(sprintf('Erased %d subject(s).', $result['completed']));
        }

        if ($result['failed'] === 0) {
            return $reminderFailures > 0 ? self::FAILURE : self::SUCCESS;
        }

        // Exit non-zero so the scheduler surfaces this. A silently failing erasure
        // is worse than a loud one: the subject believes they are gone.
        $this->components->error(sprintf('%d erasure(s) failed:', $result['failed']));

        foreach ($result['errors'] as $subjectId => $message) {
            $this->components->twoColumnDetail((string) $subjectId, $message);
        }

        return self::FAILURE;
    }

    /**
     * Snapshot-and-verify around each erasure.
     *
     * Silently skipped when discovery cannot run, erasure still happening is
     * more important than evidence of it, and the absence shows up as a request
     * with no verification attached rather than as a fabricated pass.
     */
    private function auditor(Discoverer $discoverer, Verifier $verifier): ?ErasureAuditor
    {
        if ($this->option('no-verify') || ! config('privacy.verification.enabled', true)) {
            return null;
        }

        try {
            $manifest = $this->resolveManifest($discoverer);
            $subject = $this->resolveSubject(null);
        } catch (\Throwable) {
            return null;
        }

        if ($manifest === null || $subject === null) {
            return null;
        }

        return new SnapshotAuditor($manifest, $subject, $verifier);
    }

    /** @return int Number of reminders that failed to send. */
    private function sendReminders(DeletionSchedule $schedule, SubjectNotifier $notifier): int
    {
        if ($schedule->remindDays() === []) {
            return 0;
        }

        $result = $schedule->sendReminders($notifier);

        if ($result['sent'] > 0) {
            $this->components->info(sprintf('Sent %d reminder(s).', $result['sent']));
        }

        if ($result['failed'] === 0) {
            return 0;
        }

        // Surfaced loudly: a reminder that never arrives means the subject's
        // last chance to keep their account passes without them hearing about it.
        $this->components->error(sprintf('%d reminder(s) failed to send:', $result['failed']));

        foreach ($result['errors'] as $subjectId => $message) {
            $this->components->twoColumnDetail((string) $subjectId, $message);
        }

        return $result['failed'];
    }
}
