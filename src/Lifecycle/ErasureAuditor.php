<?php

declare(strict_types=1);

namespace PrivacyCI\Lifecycle;

/**
 * Records what was there before erasure, and what remained after.
 *
 * Split into two calls because the ordering is the entire point: once the
 * subject's row is gone, the addresses that would have been checked can no
 * longer be derived from it.
 */
interface ErasureAuditor
{
    /** Capture the footprint. Returns the request with the snapshot attached. */
    public function beforeDelete(DeletionRequest $request): DeletionRequest;

    /** Check the captured footprint against reality. */
    public function afterDelete(DeletionRequest $request): DeletionRequest;
}
