<?php

declare(strict_types=1);

namespace PrivacyCI\Lifecycle;

/**
 * The grace period, and the rules that make it trustworthy.
 *
 * There is no undo. Accepting the request, waiting, then allowing the subject
 * to cancel is what stops the first mistaken deletion from being the last one a
 * customer ever lets us perform.
 *
 * Storage-agnostic on purpose: the guarantees below are enforced here, once,
 * rather than being re-implemented by every store.
 */
final class DeletionSchedule
{
    /**
     * Fourteen rather than thirty, because the window is only half the story.
     * Art. 12(3) allows one month to respond; a thirty-day hold spends the whole
     * allowance and leaves nothing if the erasure then fails.
     */
    public const DEFAULT_GRACE_DAYS = 14;

    public function __construct(
        private readonly DeletionStore $store,
        private readonly Clock $clock = new SystemClock,
        private readonly int $graceDays = self::DEFAULT_GRACE_DAYS,
        private readonly LifecycleMode $mode = LifecycleMode::Hold,
        private readonly ?SubjectSuspender $suspender = null,
        /** @var list<int> Days-before marks at which to remind the subject. */
        private readonly array $remindDays = [],
    ) {
        if ($this->graceDays < 0) {
            throw new \InvalidArgumentException('Grace period cannot be negative.');
        }

        if ($this->mode->suspendsImmediately() && $this->suspender === null) {
            throw new \InvalidArgumentException(
                'Suspend mode requires a SubjectSuspender; otherwise the subject is told '
                .'their account is closed while it stays fully active.',
            );
        }
    }

    public function graceDays(): int
    {
        return $this->graceDays;
    }

    public function mode(): LifecycleMode
    {
        return $this->mode;
    }

    /** @return list<int> */
    public function remindDays(): array
    {
        $marks = array_values(array_unique(array_filter(
            $this->remindDays,
            static fn (int $d): bool => $d > 0,
        )));
        sort($marks);

        return $marks;
    }

    /**
     * Warn subjects whose window is closing.
     *
     * Run this before process(): otherwise a "1 day left" reminder can go out on
     * the same tick that erases the account it refers to.
     *
     * @return array{sent: int, failed: int, errors: array<string, string>}
     */
    public function sendReminders(SubjectNotifier $notifier, int $limit = 500): array
    {
        $marks = $this->remindDays();

        if ($marks === []) {
            return ['sent' => 0, 'failed' => 0, 'errors' => []];
        }

        $now = $this->clock->now();
        $horizon = $now->add(new \DateInterval('P'.max($marks).'D'));

        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach ($this->store->pendingWithin($horizon, $limit) as $request) {
            $due = $request->dueReminders($marks, $now);

            if ($due === []) {
                continue;
            }

            // Send only the most urgent applicable mark, but record all of them.
            // If the scheduler was down for a week, the subject should hear
            // "1 day left", not "7 days left" followed by a correction.
            $mostUrgent = $due[0];
            $remaining = $request->daysRemaining($now);

            try {
                $notifier->remind($request, $remaining);
                $this->store->save($request->remindersRecorded($due));
                $sent++;
            } catch (\Throwable $e) {
                // Deliberately not recorded as sent: a reminder that failed to
                // send must be retried on the next tick, because it is the
                // subject's last chance to keep their account.
                $errors[$request->subjectId] = $e->getMessage();
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * Accept an erasure request and start the clock.
     *
     * Idempotent by design: asking twice returns the original request rather than
     * restarting the window. Otherwise a user tapping "delete my account" twice
     * would quietly buy themselves another thirty days.
     */
    public function request(
        string $subjectType,
        string $subjectId,
        ?string $via = null,
        ?string $policyFingerprint = null,
    ): DeletionRequest {
        $existing = $this->store->pendingFor($subjectType, $subjectId);

        if ($existing !== null) {
            return $existing;
        }

        $now = $this->clock->now();

        $request = new DeletionRequest(
            id: $this->newId(),
            subjectType: $subjectType,
            subjectId: $subjectId,
            requestedAt: $now,
            executeAfter: $now->add(new \DateInterval("P{$this->graceDays}D")),
            status: DeletionStatus::PendingDelete,
            requestedVia: $via,
            policyFingerprint: $policyFingerprint,
        );

        // Record the request before acting on it, so a suspension that fails
        // half-way still leaves a trace of what was attempted.
        $this->store->save($request);

        if (! $this->mode->suspendsImmediately()) {
            return $request;
        }

        try {
            $this->suspender?->suspend($request);
        } catch (\Throwable $e) {
            // Roll the request back rather than leaving the subject in limbo:
            // believing they are closed while still being processed is the one
            // outcome worse than not accepting the request at all.
            $this->store->save($request->cancelled(
                $this->clock->now(),
                'suspension failed: '.$e->getMessage(),
            ));

            throw $e;
        }

        $suspended = $request->suspended($this->clock->now());
        $this->store->save($suspended);

        return $suspended;
    }

    /**
     * Call off a pending erasure, the user logged back in or asked us to stop.
     *
     * Returns null when there was nothing to cancel, so callers can fire this on
     * every login without first checking.
     */
    public function cancel(string $subjectType, string $subjectId, string $reason): ?DeletionRequest
    {
        $pending = $this->store->pendingFor($subjectType, $subjectId);

        if ($pending === null) {
            return null;
        }

        $cancelled = $pending->cancelled($this->clock->now(), $reason);
        $this->store->save($cancelled);

        return $cancelled;
    }

    /**
     * Restore a subject who changed their mind.
     *
     * In hold mode this is just a cancellation. In suspend mode it also puts the
     * account back by unlocking, re-indexing and re-subscribing, or whatever
     * else the application's suspender undoes. Reactivation runs *before* the
     * closed, so a failure leaves the request pending and recoverable rather
     * than closed over a still-suspended account.
     */
    public function reactivate(
        string $subjectType,
        string $subjectId,
        string $reason = 'reactivated by the subject',
    ): ?DeletionRequest {
        $pending = $this->store->pendingFor($subjectType, $subjectId);

        if ($pending === null) {
            return null;
        }

        if ($this->mode->suspendsImmediately() && $pending->isSuspended()) {
            $this->suspender?->reactivate($pending);
        }

        $cancelled = $pending->cancelled($this->clock->now(), $reason);
        $this->store->save($cancelled);

        return $cancelled;
    }

    /**
     * Convenience for the login listener.
     *
     * Only meaningful in hold mode. Under suspension the subject cannot sign in,
     * so cancellation must be an explicit act. See LifecycleMode.
     */
    public function cancelOnActivity(string $subjectType, string $subjectId): ?DeletionRequest
    {
        if (! $this->mode->cancelsOnLogin()) {
            return null;
        }

        return $this->cancel($subjectType, $subjectId, 'subject signed in during the grace period');
    }

    public function pendingFor(string $subjectType, string $subjectId): ?DeletionRequest
    {
        return $this->store->pendingFor($subjectType, $subjectId);
    }

    /** @return list<DeletionRequest> */
    public function due(int $limit = 100): array
    {
        return $this->store->due($this->clock->now(), $limit);
    }

    /**
     * Run every request whose grace period has elapsed.
     *
     * A failure is recorded against that one request and the loop continues: one
     * subject whose S3 bucket is unreachable must not block everybody else's
     * erasure, and their request stays visible for a human to pick up.
     *
     * @return array{completed: int, failed: int, errors: array<string, string>}
     */
    public function process(
        SubjectDeleter $deleter,
        int $limit = 100,
        ?ErasureAuditor $auditor = null,
    ): array {
        $completed = 0;
        $failed = 0;
        $errors = [];

        foreach ($this->due($limit) as $request) {
            // Claim it first, so a second scheduler tick cannot pick up the same
            // request while this one is still working.
            $claimed = $request->executing($this->clock->now());
            $this->store->save($claimed);

            try {
                // Snapshot before, verify after. Reversing this would leave
                // nothing to check against.
                if ($auditor !== null) {
                    $claimed = $auditor->beforeDelete($claimed);
                    $this->store->save($claimed);
                }

                $deleter->delete($claimed);

                if ($auditor !== null) {
                    $claimed = $auditor->afterDelete($claimed);
                }

                $this->store->save($claimed->completed($this->clock->now()));
                $completed++;
            } catch (\Throwable $e) {
                $this->store->save($claimed->failed($this->clock->now(), $e->getMessage()));
                $errors[$claimed->subjectId] = $e->getMessage();
                $failed++;
            }
        }

        return ['completed' => $completed, 'failed' => $failed, 'errors' => $errors];
    }

    private function newId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
