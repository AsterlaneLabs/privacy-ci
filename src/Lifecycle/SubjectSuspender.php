<?php

declare(strict_types=1);

namespace PrivacyCI\Lifecycle;

/**
 * Stops and restarts processing of a subject's data.
 *
 * The application implements this, because only it knows what "stop processing"
 * means for its own product. Typically: set the account status to
 * pending_delete, revoke sessions and tokens, hide the profile from other users,
 * unsubscribe from marketing sends, and drop them from search indexes,
 * recommendations and analytics exports.
 *
 * Nothing here deletes anything. Every change must be reversible by reactivate(),
 * because the entire point of the window is that the subject can come back.
 */
interface SubjectSuspender
{
    /** @throws \Throwable  A failure aborts the request rather than half-suspending. */
    public function suspend(DeletionRequest $request): void;

    /** @throws \Throwable */
    public function reactivate(DeletionRequest $request): void;
}
