<?php

declare(strict_types=1);

namespace PrivacyCI\Manifest;

/**
 * How a discovered location should be handled when a subject exercises their rights.
 *
 * UNCLASSIFIED is the only state the CI check can fail on, and only when the
 * location is new relative to the committed baseline.
 */
enum Classification: string
{
    case Delete = 'DELETE';
    case Anonymize = 'ANONYMIZE';
    case Retain = 'RETAIN';
    case Ignore = 'IGNORE';
    case Custom = 'CUSTOM';
    case Unclassified = 'UNCLASSIFIED';

    /** Whether a location in this state satisfies the policy check. */
    public function isResolved(): bool
    {
        return $this !== self::Unclassified;
    }

    /**
     * RETAIN and IGNORE both need a documented reason.
     *
     * RETAIN because an auditor asks for it first. IGNORE because it is the
     * suppression mechanism, and an unexplained suppression is indistinguishable
     * from an oversight when someone reads the diff two years later.
     */
    public function requiresReason(): bool
    {
        return in_array($this, [self::Retain, self::Ignore], true);
    }
}
