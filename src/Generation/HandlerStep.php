<?php

declare(strict_types=1);

namespace PrivacyCI\Generation;

use PrivacyCI\Manifest\Classification;

/** One step in a generated handler, in the order it must run. */
final readonly class HandlerStep
{
    /**
     * @param  array<string, mixed>  $replacements
     * @param  list<string>          $notes
     */
    public function __construct(
        public string $label,
        public Classification $classification,
        public int $depth,
        public ?string $modelClass = null,
        public ?string $table = null,
        public ?string $foreignKey = null,
        public array $replacements = [],
        public ?string $handler = null,
        public ?string $store = null,
        public ?string $pattern = null,
        public array $notes = [],
        public bool $actionable = true,
        public ?\PrivacyCI\Manifest\LocationKind $kind = null,
        public bool $isSubjectRoot = false,
    ) {
    }

    /** Object storage needs a disk and a path; everything else needs a key. */
    public function kindIsStorage(): bool
    {
        return $this->kind === \PrivacyCI\Manifest\LocationKind::ObjectStorage;
    }
}
