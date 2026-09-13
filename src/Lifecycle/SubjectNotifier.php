<?php

declare(strict_types=1);

namespace PrivacyCI\Lifecycle;

/**
 * Reaches a subject during the grace period.
 *
 * A third interface rather than a config'd email column, for the same reason as
 * the other two: only the application knows how to contact a person. Unlike the
 * first notice, which your suspender sends, because it already has the user,
 * reminders fire days later from a scheduled command with nothing but an id, so
 * the resolution has to happen here.
 */
interface SubjectNotifier
{
    /**
     * @param  int  $daysRemaining  How long they have left, already rounded up.
     *
     * @throws \Throwable  Recorded against that one request; everyone else still gets theirs.
     */
    public function remind(DeletionRequest $request, int $daysRemaining): void;
}
