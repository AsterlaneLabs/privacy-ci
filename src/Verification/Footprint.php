<?php

declare(strict_types=1);

namespace PrivacyCI\Verification;

/**
 * Everywhere one subject was known to exist, captured before erasure ran.
 *
 * This is the artefact that makes deletion provable rather than merely done,
 * and the reason verification has to be built before orchestration: the
 * resolver that produces it is most of a deletion engine already.
 */
final readonly class Footprint
{
    /** @param list<Address> $addresses */
    public function __construct(
        public string $subjectType,
        public string $subjectId,
        public array $addresses = [],
        public ?string $capturedAt = null,
        public ?string $policyFingerprint = null,
    ) {
    }

    /** @return list<Address> */
    public function checkable(): array
    {
        return array_values(array_filter(
            $this->addresses,
            static fn (Address $a): bool => $a->isCheckable(),
        ));
    }

    /** @return list<Address> */
    public function unverifiable(): array
    {
        return array_values(array_filter(
            $this->addresses,
            static fn (Address $a): bool => ! $a->isCheckable(),
        ));
    }

    public function isEmpty(): bool
    {
        return $this->addresses === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'captured_at' => $this->capturedAt,
            'policy_fingerprint' => $this->policyFingerprint,
            'addresses' => array_map(static fn (Address $a): array => $a->toArray(), $this->addresses),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function fromJson(string $json): self
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return new self(
            (string) ($data['subject_type'] ?? 'user'),
            (string) ($data['subject_id'] ?? ''),
            array_map(Address::fromArray(...), (array) ($data['addresses'] ?? [])),
            isset($data['captured_at']) ? (string) $data['captured_at'] : null,
            isset($data['policy_fingerprint']) ? (string) $data['policy_fingerprint'] : null,
        );
    }
}
