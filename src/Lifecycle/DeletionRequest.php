<?php

declare(strict_types=1);

namespace PrivacyCI\Lifecycle;

/**
 * A subject's request to be erased, and the grace period attached to it.
 *
 * Immutable: every transition returns a new instance, so an audit trail can keep
 * the earlier states rather than overwriting them.
 */
final readonly class DeletionRequest
{
    public function __construct(
        public string $id,
        public string $subjectType,
        public string $subjectId,
        public \DateTimeImmutable $requestedAt,
        public \DateTimeImmutable $executeAfter,
        public DeletionStatus $status = DeletionStatus::PendingDelete,
        public ?string $requestedVia = null,
        public ?\DateTimeImmutable $resolvedAt = null,
        public ?string $resolvedReason = null,
        public ?string $policyFingerprint = null,
        public int $attempts = 0,
        public ?\DateTimeImmutable $suspendedAt = null,
        /** @var list<int> Reminder marks already delivered, so none is sent twice. */
        public array $remindersSent = [],
        /** Footprint captured before erasure ran, as JSON. */
        public ?string $footprintJson = null,
        /** Verification result captured after erasure ran, as JSON. */
        public ?string $verificationJson = null,
    ) {
    }

    public function withFootprint(string $json): self
    {
        return $this->copy(footprintJson: $json);
    }

    public function withVerification(string $json): self
    {
        return $this->copy(verificationJson: $json);
    }

    private function copy(?string $footprintJson = null, ?string $verificationJson = null): self
    {
        return new self(
            $this->id, $this->subjectType, $this->subjectId,
            $this->requestedAt, $this->executeAfter,
            $this->status, $this->requestedVia,
            $this->resolvedAt, $this->resolvedReason, $this->policyFingerprint,
            $this->attempts, $this->suspendedAt, $this->remindersSent,
            $footprintJson ?? $this->footprintJson,
            $verificationJson ?? $this->verificationJson,
        );
    }

    /**
     * Which reminder marks still apply, given how long is left.
     *
     * Uses <= rather than == so a scheduler that missed a day still sends the
     * reminder, late, instead of skipping it entirely.
     *
     * @param  list<int>  $marks  Configured days-before values.
     * @return list<int>  Applicable and not yet sent, most urgent first.
     */
    public function dueReminders(array $marks, \DateTimeImmutable $now): array
    {
        $remaining = $this->daysRemaining($now);

        $due = array_values(array_filter(
            $marks,
            fn (int $mark): bool => $remaining <= $mark && ! in_array($mark, $this->remindersSent, true),
        ));

        sort($due);

        return $due;
    }

    /**
     * @param  list<int>  $marks
     */
    public function remindersRecorded(array $marks): self
    {
        $merged = array_values(array_unique([...$this->remindersSent, ...$marks]));
        sort($merged);

        return new self(
            $this->id, $this->subjectType, $this->subjectId,
            $this->requestedAt, $this->executeAfter,
            $this->status, $this->requestedVia,
            $this->resolvedAt, $this->resolvedReason, $this->policyFingerprint,
            $this->attempts, $this->suspendedAt, $merged,
            $this->footprintJson, $this->verificationJson,
        );
    }

    /** True once processing has actually been stopped, not merely scheduled to stop. */
    public function isSuspended(): bool
    {
        return $this->suspendedAt !== null;
    }

    public function suspended(\DateTimeImmutable $at): self
    {
        return new self(
            $this->id, $this->subjectType, $this->subjectId,
            $this->requestedAt, $this->executeAfter,
            $this->status, $this->requestedVia,
            $this->resolvedAt, $this->resolvedReason, $this->policyFingerprint,
            $this->attempts, $at, $this->remindersSent,
            $this->footprintJson, $this->verificationJson,
        );
    }

    /** Seconds remaining before this becomes irreversible. Zero once due. */
    public function secondsRemaining(\DateTimeImmutable $now): int
    {
        return max(0, $this->executeAfter->getTimestamp() - $now->getTimestamp());
    }

    public function daysRemaining(\DateTimeImmutable $now): int
    {
        return (int) ceil($this->secondsRemaining($now) / 86400);
    }

    public function isDue(\DateTimeImmutable $now): bool
    {
        return $this->status === DeletionStatus::PendingDelete
            && $this->executeAfter <= $now;
    }

    public function cancelled(\DateTimeImmutable $at, string $reason): self
    {
        return $this->transition(DeletionStatus::Cancelled, $at, $reason);
    }

    public function executing(\DateTimeImmutable $at): self
    {
        return new self(
            $this->id, $this->subjectType, $this->subjectId,
            $this->requestedAt, $this->executeAfter,
            DeletionStatus::Executing, $this->requestedVia,
            $this->resolvedAt, $this->resolvedReason, $this->policyFingerprint,
            $this->attempts + 1, $this->suspendedAt, $this->remindersSent,
            $this->footprintJson, $this->verificationJson,
        );
    }

    public function completed(\DateTimeImmutable $at): self
    {
        return $this->transition(DeletionStatus::Completed, $at, null);
    }

    public function failed(\DateTimeImmutable $at, string $reason): self
    {
        return $this->transition(DeletionStatus::Failed, $at, $reason);
    }

    private function transition(DeletionStatus $status, \DateTimeImmutable $at, ?string $reason): self
    {
        return new self(
            $this->id, $this->subjectType, $this->subjectId,
            $this->requestedAt, $this->executeAfter,
            $status, $this->requestedVia,
            $at, $reason, $this->policyFingerprint, $this->attempts, $this->suspendedAt,
            $this->remindersSent, $this->footprintJson, $this->verificationJson,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'status' => $this->status->value,
            'requested_at' => $this->requestedAt->format(DATE_ATOM),
            'execute_after' => $this->executeAfter->format(DATE_ATOM),
            'requested_via' => $this->requestedVia,
            'resolved_at' => $this->resolvedAt?->format(DATE_ATOM),
            'resolved_reason' => $this->resolvedReason,
            'policy_fingerprint' => $this->policyFingerprint,
            'attempts' => $this->attempts,
            'suspended_at' => $this->suspendedAt?->format(DATE_ATOM),
            'reminders_sent' => $this->remindersSent,
            'verified' => $this->verificationJson !== null,
        ];
    }
}
