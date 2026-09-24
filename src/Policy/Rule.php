<?php

declare(strict_types=1);

namespace PrivacyCI\Policy;

use PrivacyCI\Manifest\Classification;
use PrivacyCI\Manifest\LocationKind;

/**
 * One declaration from a privacy policy.
 *
 * Mutable only through the fluent setters, so `$this->retain(Order::class)
 * ->reason('...')` reads the way the plan's example does.
 */
final class Rule
{
    private ?string $reason = null;

    private ?string $handler = null;

    /**
     * @param  list<string>|null        $columns       Null means the rule covers the whole target.
     * @param  array<string, mixed>     $replacements  Column => replacement, for ANONYMIZE.
     *                                                 Kept so a generated handler can write the
     *                                                 update rather than leaving a TODO.
     * @param  bool                     $storeStated   Whether the policy *named* the store, as
     *                                                 opposed to falling back to the default.
     *                                                 The difference decides whether this rule
     *                                                 may overwrite a store discovery detected.
     */
    public function __construct(
        public readonly string $target,
        public readonly Classification $classification,
        public readonly LocationKind $kind = LocationKind::DatabaseColumn,
        public readonly ?array $columns = null,
        public readonly string $store = 'primary',
        public readonly ?string $declaredAt = null,
        public readonly array $replacements = [],
        public readonly bool $storeStated = true,
    ) {
    }

    public function reason(string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    public function using(string $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    public function reasonText(): ?string
    {
        return $this->reason;
    }

    public function handler(): ?string
    {
        return $this->handler;
    }

    /** A rule naming specific columns beats one covering the whole table. */
    public function isColumnSpecific(): bool
    {
        return $this->columns !== null;
    }

    public function coversColumn(string $column): bool
    {
        return $this->columns === null || in_array($column, $this->columns, true);
    }
}
