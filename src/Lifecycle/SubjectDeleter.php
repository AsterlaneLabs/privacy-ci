<?php

declare(strict_types=1);

namespace PrivacyCI\Lifecycle;

/**
 * Performs the actual erasure once the grace period has elapsed.
 *
 * The application implements this. We own the *timing*, the request, the grace
 * window, the cancellation, the audit trail and they own the destructive part,
 * which is rung 1 of the plan's deletion ladder. When the orchestrator arrives it
 * becomes just another implementation of this interface, and nothing above it changes.
 */
interface SubjectDeleter
{
    /**
     * @throws \Throwable  Anything thrown marks the request failed and leaves it
     *                     for a human; the subject is never silently half-deleted.
     */
    public function delete(DeletionRequest $request): void;
}
