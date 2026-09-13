<?php

declare(strict_types=1);

namespace PrivacyCI\Check;

use PrivacyCI\Baseline\Baseline;
use PrivacyCI\Manifest\Location;
use PrivacyCI\Manifest\Manifest;

/**
 * Decides what a build should do about a manifest.
 *
 * Two rules, and neither is configurable:
 *
 * 1. Only deterministic findings can fail, a foreign key or a declared
 *    relationship. A name match may warn and nothing more. One false positive
 *    that blocks a deploy costs more trust than a missed column ever will.
 *
 * 2. Only *new* findings can fail. Anything in the baseline pre-dates adoption
 *    and is somebody else's debt; failing on it turns day one into three
 *    hundred failures and the workflow gets deleted.
 */
final class PolicyCheck
{
    public function run(Manifest $manifest, Baseline $baseline): CheckResult
    {
        $violations = [];
        $grandfathered = [];
        $warnings = [];
        $seenWarnings = [];

        foreach ($manifest->locations() as $location) {
            if ($location->classification->isResolved()) {
                continue;
            }

            if (! $location->linkage->isDeterministic()) {
                // A warning already in the baseline has been looked at once.
                // Listing it again every run is how a hundred-line report
                // trains people to skip the whole section.
                if ($baseline->covers($location->id)) {
                    $seenWarnings[] = $location;
                } else {
                    $warnings[] = $location;
                }

                continue;
            }

            if ($baseline->covers($location->id)) {
                $grandfathered[] = $location;

                continue;
            }

            $violations[] = $location;
        }

        return new CheckResult(
            violations: $this->sorted($violations),
            grandfathered: $this->sorted($grandfathered),
            warnings: $this->sorted($warnings),
            stale: $baseline->staleAgainst($manifest),
            seenWarnings: $this->sorted($seenWarnings),
        );
    }

    /**
     * @param  list<Location>  $locations
     * @return list<Location>
     */
    private function sorted(array $locations): array
    {
        usort($locations, static fn (Location $a, Location $b): int => $a->path <=> $b->path);

        return $locations;
    }
}
