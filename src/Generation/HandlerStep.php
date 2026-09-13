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
    ) {
    }
}
