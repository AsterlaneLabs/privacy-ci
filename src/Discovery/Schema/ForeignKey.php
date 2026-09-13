<?php

declare(strict_types=1);

namespace PrivacyCI\Discovery\Schema;

final readonly class ForeignKey
{
    public function __construct(
        public string $column,
        public string $referencesTable,
        public string $referencesColumn = 'id',
        public ?string $definedIn = null,
    ) {
    }
}
