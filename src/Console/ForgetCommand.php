<?php

declare(strict_types=1);

namespace PrivacyCI\Console;

use Illuminate\Console\Command;
use PrivacyCI\Lifecycle\DeletionSchedule;

/**
 * `php artisan privacy:forget {id}`, start a subject's grace period.
 * `php artisan privacy:forget {id} --cancel`, call it off.
 */
final class ForgetCommand extends Command
{
    protected $signature = 'privacy:forget
        {id : The subject identifier}
        {--subject=user : Subject type}
        {--cancel : Reactivate the subject and call off a pending request}
        {--force : Skip confirmation}';

    protected $description = 'Request erasure of a subject, or cancel a pending request';

    public function handle(DeletionSchedule $schedule): int
    {
        $id = (string) $this->argument('id');
        $type = (string) $this->option('subject');

        if ($this->option('cancel')) {
            try {
                // reactivate(), not cancel(): cancelling alone would close the
                // request and leave a suspended account suspended for good.
                $cancelled = $schedule->reactivate($type, $id, 'reactivated from the console');
            } catch (\Throwable $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            if ($cancelled === null) {
                $this->components->warn("No pending erasure for {$type} {$id}.");

                return self::SUCCESS;
            }

            $this->components->info(
                $schedule->mode()->suspendsImmediately()
                    ? "Reactivated {$type} {$id}; erasure cancelled."
                    : "Erasure cancelled for {$type} {$id}.",
            );

            return self::SUCCESS;
        }

        if ($existing = $schedule->pendingFor($type, $id)) {
            $this->components->warn(sprintf(
                'Already pending. Erasure due %s (%d day(s) away).',
                $existing->executeAfter->format('Y-m-d'),
                $existing->daysRemaining(now()->toDateTimeImmutable()),
            ));

            return self::SUCCESS;
        }

        $suspends = $schedule->mode()->suspendsImmediately();

        if (! $this->option('force') && ! $this->confirm(sprintf(
            $suspends
                ? 'Suspend %s %s now and erase in %d days?'
                : 'Start a %3$d-day erasure countdown for %s %s?',
            $type,
            $id,
            $schedule->graceDays(),
        ))) {
            return self::SUCCESS;
        }

        try {
            $request = $schedule->request($type, $id, via: 'console');
        } catch (\Throwable $e) {
            // The likeliest first-run experience: suspend mode is the default and
            // nothing is wired up yet. A stack trace here teaches nothing.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            $suspends
                ? 'Suspended. Erasure on %s unless reactivated before then.'
                : 'Erasure scheduled for %s. Signing in before then cancels it.',
            $request->executeAfter->format('Y-m-d'),
        ));

        return self::SUCCESS;
    }
}
