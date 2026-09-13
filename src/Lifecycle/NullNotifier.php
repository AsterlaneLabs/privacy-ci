<?php

declare(strict_types=1);

namespace PrivacyCI\Lifecycle;

/**
 * Refuses to pretend, like the other nulls.
 *
 * A reminder that silently does not arrive is the worst failure in this whole
 * flow: the subject's last chance to keep their account passes without them
 * ever hearing about it.
 */
final class NullNotifier implements SubjectNotifier
{
    public function remind(DeletionRequest $request, int $daysRemaining): void
    {
        throw new \LogicException(
            'Reminders are configured but no SubjectNotifier is bound. Implement '
            .'PrivacyCI\Lifecycle\SubjectNotifier and set privacy.lifecycle.notifier, '
            .'or empty privacy.lifecycle.remind_days.',
        );
    }
}
