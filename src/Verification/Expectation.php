<?php

declare(strict_types=1);

namespace PrivacyCI\Verification;

/** What we expect to find at an address after erasure has run. */
enum Expectation: string
{
    /** Nothing should match the subject here, deleted or anonymised away. */
    case Absent = 'absent';

    /** Kept on purpose. Presence is correct; we record it as evidence. */
    case Retained = 'retained';

    /**
     * We know personal data is here and cannot address it from the subject's id
     * alone, free-text columns, tables with no foreign key.
     *
     * Reported as unverified rather than passed. A check that silently passes
     * what it never looked at is worse than no check at all.
     */
    case Unverifiable = 'unverifiable';
}
