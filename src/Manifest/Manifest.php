<?php

declare(strict_types=1);

namespace PrivacyCI\Manifest;

use JsonSerializable;

/**
 * The findings manifest, the spine of the whole product.
 *
 * Every feature is an operation on this document: diff it across commits and you
 * have the CI check; sign and retain it and you have audit evidence; join several
 * and you have the cross-service map.
 *
 * Serialisation is deterministic (locations sorted by id, no timestamps in the
 * comparable body) so that two scans of identical code produce byte-identical
 * output. Without that, diffing is meaningless.
 */
final class Manifest implements JsonSerializable
{
    public const SCHEMA_VERSION = '1.0';

    /**
     * @param  list<Subject>      $subjects
     * @param  list<Location>     $locations
     * @param  list<Integration>  $integrations
     */
    public function __construct(
        public readonly string $project,
        public readonly array $subjects = [],
        private array $locations = [],
        public readonly array $integrations = [],
        public readonly ?string $environment = null,
        public readonly ?string $commit = null,
        public readonly ?string $scannedAt = null,
        public readonly ?string $policyHash = null,
    ) {
        $this->sortLocations();
    }

    /** @return list<Location> */
    public function locations(): array
    {
        return $this->locations;
    }

    /** @return list<Location> */
    public function unclassified(): array
    {
        return array_values(array_filter(
            $this->locations,
            static fn (Location $l): bool => ! $l->classification->isResolved(),
        ));
    }

    /** Locations that may legitimately fail a build: deterministic and unclassified. */
    public function blocking(): array
    {
        return array_values(array_filter(
            $this->locations,
            static fn (Location $l): bool => $l->blocksBuild(),
        ));
    }

    /** @return list<Integration> Stores we detected but cannot scan, demand signal. */
    public function unsupportedIntegrations(): array
    {
        return array_values(array_filter(
            $this->integrations,
            static fn (Integration $i): bool => ! $i->supported,
        ));
    }

    public function location(string $id): ?Location
    {
        foreach ($this->locations as $location) {
            if ($location->id === $id) {
                return $location;
            }
        }

        return null;
    }

    /**
     * Compare against an earlier manifest.
     *
     * This is the CI check. `added` holds locations that did not exist in the
     * baseline, the only ones a build is allowed to fail on.
     *
     * @return array{added: list<Location>, removed: list<Location>, reclassified: list<Location>}
     */
    public function diff(self $baseline): array
    {
        $mine = $this->keyed();
        $theirs = $baseline->keyed();

        $added = $removed = $reclassified = [];

        foreach ($mine as $id => $location) {
            if (! isset($theirs[$id])) {
                $added[] = $location;
            } elseif ($theirs[$id]->classification !== $location->classification) {
                $reclassified[] = $location;
            }
        }

        foreach ($theirs as $id => $location) {
            if (! isset($mine[$id])) {
                $removed[] = $location;
            }
        }

        return ['added' => $added, 'removed' => $removed, 'reclassified' => $reclassified];
    }

    public function withLocations(Location ...$locations): self
    {
        $clone = clone $this;
        $clone->locations = array_values($locations);
        $clone->sortLocations();

        return $clone;
    }

    /** @return array<string, Location> */
    private function keyed(): array
    {
        $out = [];
        foreach ($this->locations as $location) {
            $out[$location->id] = $location;
        }

        return $out;
    }

    private function sortLocations(): void
    {
        usort($this->locations, static fn (Location $a, Location $b): int => $a->id <=> $b->id);
        $this->locations = array_values($this->locations);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'schema_version' => self::SCHEMA_VERSION,
            'project' => $this->project,
            'environment' => $this->environment,
            'commit' => $this->commit,
            'scanned_at' => $this->scannedAt,
            'subjects' => array_map(static fn (Subject $s): array => $s->toArray(), $this->subjects),
            'locations' => array_map(static fn (Location $l): array => $l->toArray(), $this->locations),
            'integrations' => array_map(static fn (Integration $i): array => $i->toArray(), $this->integrations),
            'policy_hash' => $this->policyHash,
        ], static fn (mixed $v): bool => $v !== null && $v !== []);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Content hash over the comparable body only. It excludes
     * scanned_at and commit, so an unchanged codebase hashes identically.
     */
    public function fingerprint(): string
    {
        $body = $this->toArray();
        unset($body['scanned_at'], $body['commit']);

        return 'sha256:'.hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
    }

    public static function fromJson(string $json): self
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return self::fromArray($data);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['project'] ?? 'unknown'),
            array_map(Subject::fromArray(...), (array) ($data['subjects'] ?? [])),
            array_map(Location::fromArray(...), (array) ($data['locations'] ?? [])),
            array_map(Integration::fromArray(...), (array) ($data['integrations'] ?? [])),
            isset($data['environment']) ? (string) $data['environment'] : null,
            isset($data['commit']) ? (string) $data['commit'] : null,
            isset($data['scanned_at']) ? (string) $data['scanned_at'] : null,
            isset($data['policy_hash']) ? (string) $data['policy_hash'] : null,
        );
    }
}
