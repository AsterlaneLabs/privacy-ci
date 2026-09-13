<?php

declare(strict_types=1);

namespace PrivacyCI\Discovery\Flow;

use PrivacyCI\Manifest\LocationKind;

/** One place static analysis thinks a subject identifier leaves the database. */
final readonly class FlowFinding
{
    /** @param list<string> $evidence */
    public function __construct(
        public LocationKind $kind,
        public string $store,
        public string $pattern,
        public float $confidence,
        public array $evidence = [],
    ) {
    }
}
