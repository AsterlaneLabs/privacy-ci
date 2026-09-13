<?php

declare(strict_types=1);

namespace PrivacyCI\Reporting;

use PrivacyCI\Manifest\Classification;
use PrivacyCI\Manifest\Integration;
use PrivacyCI\Manifest\Linkage;
use PrivacyCI\Manifest\Location;
use PrivacyCI\Manifest\Manifest;

/**
 * Renders a manifest for a terminal.
 *
 * Shared by the artisan command and the standalone binary so both produce
 * identical output, the report is what users screenshot, so it should not
 * drift between entry points.
 */
final class ConsoleReport
{
    private const HIGH = 0.85;

    private const POSSIBLE = 0.45;

    public function __construct(private readonly bool $ansi = true)
    {
    }

    public function render(Manifest $manifest): string
    {
        $high = $possible = $weak = [];

        foreach ($manifest->locations() as $location) {
            match (true) {
                $location->confidence >= self::HIGH => $high[] = $location,
                $location->confidence >= self::POSSIBLE => $possible[] = $location,
                default => $weak[] = $location,
            };
        }

        // One width for the whole report, not one per section: columns that
        // shift between sections read as three unrelated tables.
        $width = 0;

        foreach ($manifest->locations() as $location) {
            $width = max($width, strlen($location->path));
        }

        $out = [];

        $out[] = $this->section('PERSONAL DATA: HIGH CONFIDENCE', $high, $width);
        $out[] = $this->section('PERSONAL DATA: POSSIBLE (review required)', $possible, $width);
        $out[] = $this->section('LOW CONFIDENCE (may embed personal data)', $weak, $width);
        $out[] = $this->integrations($manifest);
        $out[] = $this->summary($manifest);

        return implode("\n", array_filter($out, static fn (string $s): bool => $s !== ''));
    }

    /** @param list<Location> $locations */
    private function section(string $heading, array $locations, int $width): string
    {
        if ($locations === []) {
            return '';
        }

        $lines = [$this->dim($heading), ''];

        foreach ($locations as $location) {
            $lines[] = sprintf(
                '  %s  %s  %s  %s',
                str_pad($location->path, $width),
                str_pad($this->linkageLabel($location->linkage), 14),
                $this->confidence($location, 14),
                $this->classification($location->classification),
            );
        }

        return implode("\n", $lines)."\n";
    }

    private function linkageLabel(Linkage $linkage): string
    {
        return match ($linkage) {
            Linkage::SubjectRoot => 'subject root',
            Linkage::ForeignKey => 'foreign key',
            Linkage::Relationship => 'relationship',
            Linkage::Heuristic => 'name match',
            Linkage::Declared => 'declared',
            Linkage::Inferred => 'inferred',
        };
    }

    private function classification(Classification $classification): string
    {
        return match ($classification) {
            Classification::Unclassified => $this->amber('UNCLASSIFIED'),
            Classification::Retain => $this->dim($classification->value),
            default => $this->green($classification->value),
        };
    }

    /**
     * Pads before colouring, never after.
     *
     * str_pad() counts escape bytes as characters, so padding a coloured string
     * to a fixed width leaves every column a different visible size, and the
     * two branches here differ by nine invisible characters.
     */
    private function confidence(Location $location, int $width): string
    {
        $value = sprintf('%.2f', $location->confidence);

        return $location->linkage->isDeterministic()
            ? $this->green(str_pad($value.' certain', $width))
            : $this->dim(str_pad($value, $width));
    }

    private function integrations(Manifest $manifest): string
    {
        if ($manifest->integrations === []) {
            return '';
        }

        $lines = [$this->dim('STORES AND SERVICES DETECTED'), ''];

        foreach ($manifest->integrations as $integration) {
            $lines[] = sprintf(
                '  %s  %s  %s',
                str_pad($integration->kind, 18),
                str_pad($integration->detectedFrom, 28),
                $integration->supported ? $this->green('scannable') : $this->amber('not yet scannable'),
            );
        }

        // Printed on purpose: every one of these lines is a user telling
        // us which connector to build next.
        $unsupported = $manifest->unsupportedIntegrations();

        if ($unsupported !== []) {
            $names = implode(', ', array_map(
                static fn (Integration $i): string => $i->kind,
                $unsupported,
            ));

            $lines[] = '';
            $lines[] = $this->amber(sprintf(
                '  %d store%s detected that this version cannot scan: %s',
                count($unsupported),
                count($unsupported) === 1 ? '' : 's',
                $names,
            ));
            $lines[] = $this->dim('  Personal data may be flowing there unmapped.');
        }

        return implode("\n", $lines)."\n";
    }

    private function summary(Manifest $manifest): string
    {
        $total = count($manifest->locations());
        $unclassified = count($manifest->unclassified());
        $blocking = count($manifest->blocking());

        $parts = [
            sprintf('%d location%s found', $total, $total === 1 ? '' : 's'),
            sprintf('%d classified', $total - $unclassified),
            $unclassified > 0
                ? $this->amber(sprintf('%d unclassified', $unclassified))
                : $this->green('0 unclassified'),
        ];

        if ($blocking > 0) {
            $parts[] = $this->amber(sprintf('%d would fail CI', $blocking));
        }

        return implode(' · ', $parts);
    }

    private function dim(string $text): string
    {
        return $this->ansi ? "\033[2m{$text}\033[0m" : $text;
    }

    private function green(string $text): string
    {
        return $this->ansi ? "\033[32m{$text}\033[0m" : $text;
    }

    private function amber(string $text): string
    {
        return $this->ansi ? "\033[33m{$text}\033[0m" : $text;
    }
}
