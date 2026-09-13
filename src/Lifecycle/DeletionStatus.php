<?php

declare(strict_types=1);

namespace PrivacyCI\Lifecycle;

/**
 * Where a subject sits in the erasure lifecycle.
 *
 * `PendingDelete` is the grace period: the request is accepted and the clock is
 * running, but nothing irreversible has happened yet.
 */
enum DeletionStatus: string
{
    case PendingDelete = 'pending_delete';
    case Cancelled = 'cancelled';
    case Executing = 'executing';
    case Completed = 'completed';
    case Failed = 'failed';

    /** Only a pending request can still be called off by the subject. */
    public function isCancellable(): bool
    {
        return $this === self::PendingDelete;
    }

    /** Terminal states are never picked up by the scheduler again. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Completed], true);
    }
}
