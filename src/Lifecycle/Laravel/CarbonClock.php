<?php

declare(strict_types=1);

namespace PrivacyCI\Lifecycle\Laravel;

use Illuminate\Support\Carbon;
use PrivacyCI\Lifecycle\Clock;

/**
 * Reads "now" from Carbon rather than the system clock.
 *
 * Inside a Laravel application the framework's notion of the current time is the
 * authoritative one: Carbon::setTestNow() and the travel() test helpers move it,
 * and signed-URL expiry is measured against it. A package that consulted the
 * system clock instead would disagree with the very links it generates.
 */
final class CarbonClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return Carbon::now('UTC')->toDateTimeImmutable();
    }
}
