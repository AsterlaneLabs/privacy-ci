<?php

declare(strict_types=1);

namespace PrivacyCI\Lifecycle\Laravel;

use PrivacyCI\Lifecycle\DeletionRequest;
use PrivacyCI\Lifecycle\DeletionSchedule;

/**
 * Adds self-service erasure to a model.
 *
 *     $user->requestDeletion(via: 'account settings');
 *     $user->deletionPending();       // true during the grace period
 *     $user->daysUntilDeletion();     // what to show them
 *     $user->cancelDeletion();        // "actually, keep my account"
 */
trait RequestsDeletion
{
    public function requestDeletion(?string $via = null): DeletionRequest
    {
        return $this->deletionSchedule()->request(
            $this->privacySubjectType(),
            (string) $this->getKey(),
            $via,
        );
    }

    /**
     * Undo a pending erasure and, under suspend mode, put the account back.
     *
     * Prefer this over cancelDeletion(): cancelling alone closes the request but
     * leaves a suspended account suspended forever.
     */
    public function reactivate(string $reason = 'reactivated by the subject'): ?DeletionRequest
    {
        return $this->deletionSchedule()->reactivate(
            $this->privacySubjectType(),
            (string) $this->getKey(),
            $reason,
        );
    }

    /** Closes the request without restoring the account. Rarely what you want. */
    public function cancelDeletion(string $reason = 'cancelled by the subject'): ?DeletionRequest
    {
        return $this->deletionSchedule()->cancel(
            $this->privacySubjectType(),
            (string) $this->getKey(),
            $reason,
        );
    }

    /** True while processing is stopped but the data is still recoverable. */
    public function isSuspendedForDeletion(): bool
    {
        return $this->pendingDeletion()?->isSuspended() ?? false;
    }

    public function pendingDeletion(): ?DeletionRequest
    {
        return $this->deletionSchedule()->pendingFor(
            $this->privacySubjectType(),
            (string) $this->getKey(),
        );
    }

    public function deletionPending(): bool
    {
        return $this->pendingDeletion() !== null;
    }

    /** Null when no erasure is pending. */
    public function daysUntilDeletion(): ?int
    {
        $pending = $this->pendingDeletion();

        return $pending?->daysRemaining(now()->toDateTimeImmutable());
    }

    /**
     * Override on the model when one class is not the whole story, for example
     * when staff and customers share a table but are erased under different rules.
     */
    public function privacySubjectType(): string
    {
        return 'user';
    }

    private function deletionSchedule(): DeletionSchedule
    {
        return app(DeletionSchedule::class);
    }
}
