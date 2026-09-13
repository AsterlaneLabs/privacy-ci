<?php

declare(strict_types=1);

namespace PrivacyCI\Verification;

/** Looks in one kind of store and answers whether the subject is still there. */
interface Probe
{
    /**
     * Takes the whole address rather than just the kind: a cache key and a raw
     * Redis key are the same kind and need different clients.
     */
    public function handles(Address $address): bool;

    /**
     * @return bool  True if anything matching the subject remains.
     *
     * @throws \Throwable  Recorded as an error, never as a pass, a store we
     *                     could not reach has told us nothing.
     */
    public function exists(Address $address): bool;
}
