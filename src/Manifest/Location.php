<?php

declare(strict_types=1);

namespace PrivacyCI\Manifest;

/**
 * One place personal data lives.
 *
 * Carries structure and identifiers, never values. The whole manifest can be
 * printed, diffed and signed precisely because this class holds no data.
 */
final readonly class Location
{
    /** @param list<string> $evidence Why we believe this, in human-readable form. */
    public function __construct(
        public string $id,
        public LocationKind $kind,
        public string $store,
        public string $path,
        public string $subject,
        public Linkage $linkage,
        public float $confidence,
        public Classification $classification = Classification::Unclassified,
        public ?string $policySource = null,
        public array $evidence = [],
        public ?string $reason = null,
    ) {
    }

    /** Stable identity for a database column, used as the manifest key. */
    public static function idFor(LocationKind $kind, string $store, string $path): string
    {
        return match ($kind) {
            LocationKind::DatabaseColumn => "db:{$store}:{$path}",
            LocationKind::WarehouseColumn => "warehouse:{$store}:{$path}",
            LocationKind::RedisKey => "redis:{$store}:{$path}",
            LocationKind::ObjectStorage => "storage:{$store}:{$path}",
            LocationKind::SearchIndex => "search:{$store}:{$path}",
            LocationKind::ExternalService => "service:{$store}:{$path}",
            LocationKind::LogStream => "log:{$store}:{$path}",
        };
    }

    /** Only a deterministic, unclassified location may fail a build. */
    public function blocksBuild(): bool
    {
        return ! $this->classification->isResolved() && $this->linkage->isDeterministic();
    }

    public function withClassification(
        Classification $classification,
        ?string $policySource = null,
        ?string $reason = null,
    ): self {
        return new self(
            $this->id,
            $this->kind,
            $this->store,
            $this->path,
            $this->subject,
            $this->linkage,
            $this->confidence,
            $classification,
            $policySource,
            $this->evidence,
            $reason,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'kind' => $this->kind->value,
            'store' => $this->store,
            'path' => $this->path,
            'subject' => $this->subject,
            'linkage' => $this->linkage->value,
            'confidence' => round($this->confidence, 2),
            'classification' => $this->classification->value,
            'policy_source' => $this->policySource,
            'reason' => $this->reason,
            'evidence' => $this->evidence,
        ], static fn (mixed $v): bool => $v !== null && $v !== []);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            LocationKind::from((string) $data['kind']),
            (string) $data['store'],
            (string) $data['path'],
            (string) $data['subject'],
            Linkage::from((string) $data['linkage']),
            (float) $data['confidence'],
            Classification::from((string) ($data['classification'] ?? 'UNCLASSIFIED')),
            isset($data['policy_source']) ? (string) $data['policy_source'] : null,
            array_values((array) ($data['evidence'] ?? [])),
            isset($data['reason']) ? (string) $data['reason'] : null,
        );
    }
}
