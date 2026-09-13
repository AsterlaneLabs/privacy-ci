<?php

declare(strict_types=1);

namespace PrivacyCI\Lifecycle\Events;

use PrivacyCI\Lifecycle\DeletionRequest;

/**
 * An erasure did not finish. The request is left for a human.
 *
 * Plain object with no framework dependency. Laravel's event() accepts it, so
 * an application can log it, alert on it, or mirror it into an audit table of
 * its own using machinery it already has.
 */
final readonly class DeletionFailed
{
    public function __construct(public DeletionRequest $request)
    {
    }
}
