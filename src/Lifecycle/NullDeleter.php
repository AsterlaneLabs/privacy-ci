<?php

declare(strict_types=1);

namespace PrivacyCI\Lifecycle;

/**
 * Refuses to pretend.
 *
 * Shipping a no-op default that silently reports success would mean an
 * application could run this in production, see "completed" and have deleted
 * nothing at all. Failing loudly is the only safe default.
 */
final class NullDeleter implements SubjectDeleter
{
    public function delete(DeletionRequest $request): void
    {
        throw new \LogicException(
            'No SubjectDeleter is bound. Implement PrivacyCI\Lifecycle\SubjectDeleter '
            .'and bind it in the container, or set privacy.lifecycle.deleter in config.',
        );
    }
}
