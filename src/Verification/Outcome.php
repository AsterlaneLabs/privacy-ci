<?php

declare(strict_types=1);

namespace PrivacyCI\Verification;

enum Outcome: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Retained = 'retained';

    /**
     * We did not look, no probe for this store or the probe errored.
     *
     * Deliberately distinct from Pass. Collapsing the two would let a report
     * claim a subject was erased from a store nobody ever checked.
     */
    case Unchecked = 'unchecked';

    public function isFailure(): bool
    {
        return $this === self::Fail;
    }
}
