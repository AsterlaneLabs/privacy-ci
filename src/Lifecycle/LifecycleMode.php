<?php

declare(strict_types=1);

namespace PrivacyCI\Lifecycle;

/**
 * What happens on the day a subject asks to be forgotten.
 *
 * The distinction matters legally: GDPR separates storing data from *processing*
 * it. Suspending stops the processing immediately, which means the request has
 * been substantively honoured within hours rather than at the end of the window.
 */
enum LifecycleMode: string
{
    /**
     * Nothing changes until the window closes. Simplest, and the weakest posture:
     * a long window here is a month of continued processing.
     */
    case Hold = 'hold';

    /**
     * Processing stops on day zero, the account is locked, hidden and pulled
     * from sends and indexes, while the data stays recoverable until the window
     * closes. Recommended.
     */
    case Suspend = 'suspend';

    public function suspendsImmediately(): bool
    {
        return $this === self::Suspend;
    }

    /**
     * Under suspension the subject cannot sign in, so an implicit login can no
     * longer be the cancellation signal, reactivation has to be deliberate.
     * That is an improvement: an explicit "keep my account" is better evidence
     * than a background token refresh silently cancelling an erasure.
     */
    public function cancelsOnLogin(): bool
    {
        return $this === self::Hold;
    }
}
