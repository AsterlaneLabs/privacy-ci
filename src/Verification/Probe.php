<?php

declare(strict_types=1);

namespace PrivacyCI\Verification;

use PrivacyCI\Manifest\LocationKind;

/** Looks in one kind of store and answers whether the subject is still there. */
interface Probe
{
    public function handles(LocationKind $kind): bool;

    /**
     * @return bool  True if anything matching the subject remains.
     *
     * @throws \Throwable  Recorded as an error, never as a pass, a store we
     *                     could not reach has told us nothing.
     */
    public function exists(Address $address): bool;
}
