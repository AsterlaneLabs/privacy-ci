<?php

declare(strict_types=1);

namespace PrivacyCI\Lifecycle\Laravel;

use Illuminate\Auth\Events\Login;
use PrivacyCI\Lifecycle\DeletionSchedule;

/**
 * Signing back in during the grace period calls off the erasure.
 *
 * This is the whole point of the window: the commonest reason a deletion should
 * not proceed is that the person changed their mind, and the clearest way they
 * say so is by coming back.
 */
final class CancelDeletionOnLogin
{
    public function __construct(private readonly DeletionSchedule $schedule)
    {
    }

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! method_exists($user, 'getKey')) {
            return;
        }

        $type = method_exists($user, 'privacySubjectType')
            ? $user->privacySubjectType()
            : 'user';

        $this->schedule->cancelOnActivity($type, (string) $user->getKey());
    }
}
