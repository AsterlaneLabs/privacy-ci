<?php

declare(strict_types=1);

namespace PrivacyCI\Lifecycle;

/** A clock that does not move, so grace-period behaviour can be tested exactly. */
final class FrozenClock implements Clock
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    public static function at(string $time): self
    {
        return new self(new \DateTimeImmutable($time, new \DateTimeZone('UTC')));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->add(\DateInterval::createFromDateString($interval));
    }
}
