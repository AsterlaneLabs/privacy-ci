<?php

declare(strict_types=1);

namespace PrivacyCI\Baseline;

/**
 * One grandfathered finding.
 *
 * Carries the path and linkage alongside the id so the file reads as a review
 * document rather than a wall of opaque keys, somebody has to approve this in
 * a pull request.
 */
final readonly class BaselineEntry
{
    public function __construct(
        public string $id,
        public string $path,
        public string $linkage,
        public ?string $note = null,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'path' => $this->path,
            'linkage' => $this->linkage,
            'note' => $this->note,
        ], static fn (?string $v): bool => $v !== null);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) ($data['path'] ?? $data['id']),
            (string) ($data['linkage'] ?? 'unknown'),
            isset($data['note']) ? (string) $data['note'] : null,
        );
    }
}
