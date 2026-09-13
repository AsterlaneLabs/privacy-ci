<?php

declare(strict_types=1);

namespace PrivacyCI\Discovery\Schema;

final class Column
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public bool $nullable = false,
        public ?string $definedIn = null,
    ) {
    }
}
