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

        // One set of widths for the whole report, not one per section: columns
        // that shift between sections read as three unrelated tables.
        $widths = ['path' => strlen('location'), 'store' => strlen('store')];

        foreach ($manifest->locations() as $location) {
            $widths['path'] = max($widths['path'], strlen($location->path));
            $widths['store'] = max($widths['store'], strlen($location->store));
        }

        $out = [];

        $out[] = $this->section('PERSONAL DATA: HIGH CONFIDENCE', $high, $widths);
        $out[] = $this->section('PERSONAL DATA: POSSIBLE (review required)', $possible, $widths);
        $out[] = $this->section('LOW CONFIDENCE (may embed personal data)', $weak, $widths);
        $out[] = $this->integrations($manifest);
        $out[] = $this->summary($manifest);

        return implode("\n", array_filter($out, static fn (string $s): bool => $s !== ''));
    }

    /**
     * @param  list<Location>                    $locations
     * @param  array{path: int, store: int}      $widths
     */
    private function section(string $heading, array $locations, array $widths): string
    {
        if ($locations === []) {
            return '';
        }

        $lines = [$this->dim($heading), '', $this->header($widths)];

        foreach ($locations as $location) {
            $lines[] = sprintf(
                '  %s  %s  %s  %s  %s',
                str_pad($location->path, $widths['path']),
                $this->store($location, $widths['store']),
                str_pad($this->linkageLabel($location->linkage), 14),
                $this->confidence($location, 14),
                $this->classification($location->classification),
            );
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Names the columns.
     *
     * Four self-describing columns did not need this: nobody wonders what
     * `foreign key` or `DELETE` is. `store` does need it, because `default` and
     * `scout` are meaningless without a label, and a column nobody can name is a
     * column nobody reads.
     *
     * The words are the manifest's own, so the report and `--json` describe the
     * same finding the same way.
     *
     * @param  array{path: int, store: int}  $widths
     */
    private function header(array $widths): string
    {
        return $this->dim(sprintf(
            '  %s  %s  %s  %s  %s',
            str_pad('location', $widths['path']),
            str_pad('store', $widths['store']),
            str_pad('linkage', 14),
            str_pad('confidence', 14),
            'classification',
        ));
    }

    /**
     * Where this location physically lives.
     *
     * Two indexes named `users/{id}` are different places depending on whether
     * Scout is backed by OpenSearch or nobody has said yet, and until this
     * column existed the answer was only in `--json`.
     *
     * `primary` is dimmed rather than left blank. A hole on four rows in five
     * makes a ragged column out of the one a reader is scanning precisely to
     * answer "where does this live?", and the database is an answer to that
     * question like any other.
     */
    private function store(Location $location, int $width): string
    {
        $padded = str_pad($location->store, $width);

        return $location->store === 'primary' ? $this->dim($padded) : $padded;
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

        // Measured, not assumed. A fixed 28 fitted 'config/filesystems.php' and
        // not 'opensearch-project/opensearch-php', which pushed the last column
        // out on exactly the row a reader most wants to scan.
        $kindWidth = 18;
        $sourceWidth = 28;

        foreach ($manifest->integrations as $integration) {
            $kindWidth = max($kindWidth, strlen($integration->kind));
            $sourceWidth = max($sourceWidth, strlen($integration->detectedFrom));
        }

        foreach ($manifest->integrations as $integration) {
            $lines[] = sprintf(
                '  %s  %s  %s',
                str_pad($integration->kind, $kindWidth),
                str_pad($integration->detectedFrom, $sourceWidth),
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
