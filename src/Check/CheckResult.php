<?php

declare(strict_types=1);

namespace PrivacyCI\Check;

use PrivacyCI\Baseline\BaselineEntry;
use PrivacyCI\Manifest\Location;

/**
 * What the check found, partitioned by what a build should do about it.
 */
final readonly class CheckResult
{
    /**
     * @param  list<Location>       $violations    Deterministic, unclassified, not baselined. These fail.
     * @param  list<Location>       $grandfathered Deterministic and unclassified, but pre-dating adoption.
     * @param  list<Location>       $warnings      Probabilistic findings worth a look.
     * @param  list<Location>        $seenWarnings  Probabilistic findings that pre-date adoption.
     * @param  list<BaselineEntry>  $stale         Baselined ids the manifest no longer reports.
     */
    public function __construct(
        public array $violations = [],
        public array $grandfathered = [],
        public array $warnings = [],
        public array $stale = [],
        public array $seenWarnings = [],
    ) {
    }

    public function passes(): bool
    {
        return $this->violations === [];
    }

    public function hasAnythingToSay(): bool
    {
        return $this->violations !== []
            || $this->grandfathered !== []
            || $this->warnings !== []
            || $this->seenWarnings !== []
            || $this->stale !== [];
    }
}
