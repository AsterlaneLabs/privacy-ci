<?php

declare(strict_types=1);

namespace PrivacyCI\Lifecycle;

/**
 * Refuses to pretend, for the same reason NullDeleter does.
 *
 * In suspend mode the subject is told their account is closed. Quietly doing
 * nothing would leave them fully active while believing otherwise.
 */
final class NullSuspender implements SubjectSuspender
{
    public function suspend(DeletionRequest $request): void
    {
        $this->refuse();
    }

    public function reactivate(DeletionRequest $request): void
    {
        $this->refuse();
    }

    private function refuse(): never
    {
        throw new \LogicException(
            'Lifecycle mode is "suspend" but no SubjectSuspender is bound. Implement '
            .'PrivacyCI\Lifecycle\SubjectSuspender and set privacy.lifecycle.suspender, '
            .'or set privacy.lifecycle.mode to "hold".',
        );
    }
}
