<?php

declare(strict_types=1);

namespace PrivacyCI\Lifecycle\Laravel;

use Illuminate\Contracts\Routing\UrlGenerator;
use PrivacyCI\Lifecycle\DeletionRequest;

/**
 * Builds the signed link that lets a subject call off their own erasure.
 *
 * The link reactivates and nothing else. That asymmetry is deliberate: a link in
 * an inbox is a bearer token, so the worst case here is an account that should
 * have been deleted staying alive, recoverable, since the subject can simply
 * ask again. A link that could *delete* would let anyone who forwarded it
 * destroy the account, which is not recoverable at all.
 */
final class ReactivationLink
{
    public function __construct(private readonly UrlGenerator $url)
    {
    }

    /**
     * Expires exactly when the grace period does. A link that outlives the
     * window is worse than useless, it implies a rescue that is no longer possible.
     */
    public function for(DeletionRequest $request): string
    {
        return $this->url->temporarySignedRoute(
            'privacy.reactivate',
            $request->executeAfter,
            ['request' => $request->id],
        );
    }
}
