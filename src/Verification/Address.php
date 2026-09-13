<?php

declare(strict_types=1);

namespace PrivacyCI\Verification;

use PrivacyCI\Manifest\LocationKind;

/**
 * One concrete place to look, resolved against a specific subject.
 *
 * The manifest holds patterns, `profile:{id}`, `comments.user_id`. An Address
 * holds the resolved form for one person: `profile:99`, `comments WHERE
 * user_id = 99`. Resolving has to happen *before* erasure, because afterwards
 * the row that would tell us these addresses no longer exists.
 */
final readonly class Address
{
    /** @param array<string, string> $locator */
    public function __construct(
        public string $locationId,
        public LocationKind $kind,
        public string $store,
        public string $describe,
        public Expectation $expectation,
        public array $locator = [],
        public ?string $reason = null,
    ) {
    }

    public function isCheckable(): bool
    {
        return $this->expectation !== Expectation::Unverifiable;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'location_id' => $this->locationId,
            'kind' => $this->kind->value,
            'store' => $this->store,
            'describe' => $this->describe,
            'expectation' => $this->expectation->value,
            'locator' => $this->locator,
            'reason' => $this->reason,
        ], static fn (mixed $v): bool => $v !== null && $v !== []);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['location_id'],
            LocationKind::from((string) $data['kind']),
            (string) $data['store'],
            (string) $data['describe'],
            Expectation::from((string) $data['expectation']),
            (array) ($data['locator'] ?? []),
            isset($data['reason']) ? (string) $data['reason'] : null,
        );
    }
}
