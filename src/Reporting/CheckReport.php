<?php

declare(strict_types=1);

namespace PrivacyCI\Reporting;

use PrivacyCI\Baseline\BaselineEntry;
use PrivacyCI\Check\CheckResult;
use PrivacyCI\Manifest\Location;

/**
 * Renders a check result for a terminal or a CI log.
 *
 * Violations lead, because that is what the reader has to act on. Everything
 * else is context, and is kept quieter.
 */
final class CheckReport
{
    public function __construct(private readonly bool $ansi = true)
    {
    }

    public function render(CheckResult $result): string
    {
        if (! $result->hasAnythingToSay()) {
            return $this->green('Privacy check passed. Every user-linked location has a policy.');
        }

        $out = [];

        if ($result->violations !== []) {
            $out[] = $this->violations($result->violations);
        }

        if ($result->warnings !== []) {
            $out[] = $this->block(
                'POSSIBLE PERSONAL DATA: REVIEW, NOT BLOCKING',
                $result->warnings,
                'A name match is never certain, so it will never fail a build.',
            );
        }

        if ($result->seenWarnings !== []) {
            $out[] = $this->dim(sprintf(
                "%d possible finding(s) were reviewed when you baselined; run with --all to list them.\n",
                count($result->seenWarnings),
            ));
        }

        if ($result->grandfathered !== []) {
            $out[] = $this->block(
                sprintf('PRE-EXISTING, BASELINED (%d)', count($result->grandfathered)),
                $result->grandfathered,
                'Not failing the build. Shrink this over time.',
            );
        }

        if ($result->stale !== []) {
            $out[] = $this->stale($result->stale);
        }

        $out[] = $this->verdict($result);

        return implode("\n", array_filter($out, static fn (string $s): bool => $s !== ''));
    }

    /** @param list<Location> $violations */
    private function violations(array $violations): string
    {
        $lines = [
            $this->red('PRIVACY IMPACT DETECTED'),
            '',
            '  New user-linked storage with no policy:',
            '',
        ];

        $width = $this->width($violations);

        foreach ($violations as $location) {
            $lines[] = sprintf(
                '    %s  %s',
                str_pad($location->path, $width),
                $this->dim($this->why($location)),
            );
        }

        $lines[] = '';
        $lines[] = '  Classify each in your privacy policy, or run:';
        $lines[] = $this->dim('    php artisan privacy:baseline    # grandfather pre-existing findings');

        return implode("\n", $lines)."\n";
    }

    /** @param list<Location> $locations */
    private function block(string $heading, array $locations, string $footnote): string
    {
        $lines = [$this->dim($heading), ''];
        $width = $this->width($locations);

        foreach ($locations as $location) {
            $lines[] = sprintf(
                '  %s  %s',
                str_pad($location->path, $width),
                $this->dim($this->why($location)),
            );
        }

        $lines[] = '';
        $lines[] = $this->dim('  '.$footnote);

        return implode("\n", $lines)."\n";
    }

    /** @param list<BaselineEntry> $stale */
    private function stale(array $stale): string
    {
        $lines = [$this->amber(sprintf('STALE BASELINE ENTRIES (%d)', count($stale))), ''];

        foreach ($stale as $entry) {
            $lines[] = '  '.$entry->path;
        }

        $lines[] = '';
        $lines[] = $this->dim(
            '  These no longer exist. Leaving them in silently grandfathers a future',
        );
        $lines[] = $this->dim('  column that reuses the name. Re-run privacy:baseline.');

        return implode("\n", $lines)."\n";
    }

    private function verdict(CheckResult $result): string
    {
        if ($result->passes()) {
            $parts = [$this->green('CHECK PASSED')];

            if ($result->warnings !== []) {
                $parts[] = sprintf('%d to review', count($result->warnings));
            }

            if ($result->seenWarnings !== []) {
                $parts[] = sprintf('%d previously reviewed', count($result->seenWarnings));
            }

            if ($result->grandfathered !== []) {
                $parts[] = sprintf('%d baselined', count($result->grandfathered));
            }

            return implode(' · ', $parts);
        }

        return sprintf(
            '%s · %d unclassified location%s introduced',
            $this->red('CHECK FAILED'),
            count($result->violations),
            count($result->violations) === 1 ? '' : 's',
        );
    }

    private function why(Location $location): string
    {
        return $location->evidence[0] ?? $location->linkage->value;
    }

    /** @param list<Location> $locations */
    private function width(array $locations): int
    {
        $width = 0;

        foreach ($locations as $location) {
            $width = max($width, strlen($location->path));
        }

        return $width;
    }

    private function dim(string $t): string
    {
        return $this->ansi ? "\033[2m{$t}\033[0m" : $t;
    }

    private function red(string $t): string
    {
        return $this->ansi ? "\033[31m{$t}\033[0m" : $t;
    }

    private function green(string $t): string
    {
        return $this->ansi ? "\033[32m{$t}\033[0m" : $t;
    }

    private function amber(string $t): string
    {
        return $this->ansi ? "\033[33m{$t}\033[0m" : $t;
    }
}
