<?php

declare(strict_types=1);

namespace PrivacyCI\Lifecycle;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
