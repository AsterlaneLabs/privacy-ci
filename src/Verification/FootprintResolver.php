<?php

declare(strict_types=1);

namespace PrivacyCI\Verification;

use PrivacyCI\Manifest\Classification;
use PrivacyCI\Manifest\Linkage;
use PrivacyCI\Manifest\Location;
use PrivacyCI\Manifest\LocationKind;
use PrivacyCI\Manifest\Manifest;
use PrivacyCI\Manifest\Subject;

/**
 * Resolves a manifest into the concrete addresses one subject occupies.
 *
 * Must run *before* erasure. Afterwards the row that would let us derive the
 * Redis keys, storage paths and dependent rows is gone and a check built from
 * policy alone can only confirm policy coverage, not that anything was removed.
 */
final class FootprintResolver
{
    public function resolve(
        Manifest $manifest,
        Subject $subject,
        string $subjectId,
        ?string $capturedAt = null,
    ): Footprint {
        /** @var array<string, list<Location>> $byTable */
        $byTable = [];
        $addresses = [];

        foreach ($manifest->locations() as $location) {
            if ($location->classification === Classification::Ignore) {
                continue;
            }

            if ($location->kind === LocationKind::DatabaseColumn) {
                $byTable[$this->table($location->path)][] = $location;

                continue;
            }

            $addresses[] = $this->storeAddress($location, $subjectId);
        }

        foreach ($byTable as $table => $locations) {
            $addresses[] = $this->tableAddress($table, $locations, $subject, $subjectId);
        }

        usort($addresses, static fn (Address $a, Address $b): int => $a->describe <=> $b->describe);

        return new Footprint(
            subjectType: $subject->type,
            subjectId: $subjectId,
            addresses: $addresses,
            capturedAt: $capturedAt,
            policyFingerprint: $manifest->fingerprint(),
        );
    }

    /** @param list<Location> $locations */
    private function tableAddress(
        string $table,
        array $locations,
        Subject $subject,
        string $subjectId,
    ): Address {
        $isRoot = $table === $subject->rootTable();
        $column = $isRoot ? $subject->rootColumn() : $this->foreignKey($locations);
        $retained = $this->allRetained($locations);

        if ($column === null) {
            // Personal data with no addressable link to the subject, the
            // subscribers.email case. We know it is here; we cannot look it up.
            return new Address(
                locationId: $locations[0]->id,
                kind: LocationKind::DatabaseColumn,
                store: $locations[0]->store,
                describe: $table,
                expectation: Expectation::Unverifiable,
                reason: 'no foreign key to the subject; cannot address these rows by id alone',
            );
        }

        // DELETE and ANONYMIZE converge on the same assertion: after either, no
        // row should still match the subject's id. Anonymising nulls the link,
        // which is exactly what "no longer matches" means.
        return new Address(
            locationId: $locations[0]->id,
            kind: LocationKind::DatabaseColumn,
            store: $locations[0]->store,
            describe: sprintf('%s where %s = %s', $table, $column, $subjectId),
            expectation: $retained ? Expectation::Retained : Expectation::Absent,
            locator: ['table' => $table, 'column' => $column, 'value' => $subjectId],
            reason: $retained ? $this->reason($locations) : null,
        );
    }

    private function storeAddress(Location $location, string $subjectId): Address
    {
        $resolved = $this->interpolate($location->path, $subjectId);
        $retained = $location->classification === Classification::Retain;

        // A pattern we could not resolve still contains a placeholder; checking
        // the literal string would be a guaranteed, meaningless pass.
        if (str_contains($resolved, '{')) {
            return new Address(
                locationId: $location->id,
                kind: $location->kind,
                store: $location->store,
                describe: $location->path,
                expectation: Expectation::Unverifiable,
                reason: 'key pattern contains a placeholder we cannot resolve from the subject id',
            );
        }

        return new Address(
            locationId: $location->id,
            kind: $location->kind,
            store: $location->store,
            describe: $resolved,
            expectation: $retained ? Expectation::Retained : Expectation::Absent,
            locator: ['key' => $resolved],
            reason: $retained ? $location->reason : null,
        );
    }

    /** @param list<Location> $locations */
    private function foreignKey(array $locations): ?string
    {
        foreach ($locations as $location) {
            if (in_array($location->linkage, [Linkage::ForeignKey, Linkage::Relationship], true)) {
                return $this->column($location->path);
            }
        }

        return null;
    }

    /** @param list<Location> $locations */
    private function allRetained(array $locations): bool
    {
        foreach ($locations as $location) {
            if ($location->classification !== Classification::Retain) {
                return false;
            }
        }

        return true;
    }

    /** @param list<Location> $locations */
    private function reason(array $locations): ?string
    {
        foreach ($locations as $location) {
            if ($location->reason !== null) {
                return $location->reason;
            }
        }

        return null;
    }

    private function interpolate(string $pattern, string $subjectId): string
    {
        return str_replace(
            ['{id}', '{user.id}', '{userId}', '{subject.id}', '{subjectId}'],
            $subjectId,
            $pattern,
        );
    }

    private function table(string $path): string
    {
        $pos = strrpos($path, '.');

        return $pos === false ? $path : substr($path, 0, $pos);
    }

    private function column(string $path): string
    {
        $pos = strrpos($path, '.');

        return $pos === false ? $path : substr($path, $pos + 1);
    }
}
