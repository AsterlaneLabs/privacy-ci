<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Policy\PolicyFingerprint;
use PrivacyCI\Policy\PrivacyPolicy;

final class PolicyFingerprintTest extends TestCase
{
    private function policy(callable $configure): PrivacyPolicy
    {
        return new class($configure) extends PrivacyPolicy
        {
            public function __construct(private $configure)
            {
            }

            public function configure(): void
            {
                ($this->configure)($this);
            }

            public function d(string $t): void
            {
                $this->delete($t);
            }

            public function r(string $t, string $why): void
            {
                $this->retain($t)->reason($why);
            }
        };
    }

    #[Test]
    public function the_same_rules_hash_the_same(): void
    {
        $a = $this->policy(fn ($p) => $p->d('users'));
        $b = $this->policy(fn ($p) => $p->d('users'));

        $this->assertSame(PolicyFingerprint::of($a), PolicyFingerprint::of($b));
    }

    #[Test]
    public function the_order_rules_were_written_in_does_not_matter(): void
    {
        $a = $this->policy(function ($p) { $p->d('users'); $p->d('comments'); });
        $b = $this->policy(function ($p) { $p->d('comments'); $p->d('users'); });

        $this->assertSame(PolicyFingerprint::of($a), PolicyFingerprint::of($b));
    }

    #[Test]
    public function changing_a_rule_changes_the_hash(): void
    {
        $before = $this->policy(fn ($p) => $p->d('users'));
        $after = $this->policy(function ($p) { $p->d('users'); $p->d('orders'); });

        $this->assertNotSame(PolicyFingerprint::of($before), PolicyFingerprint::of($after));
    }

    #[Test]
    public function changing_only_a_retention_reason_changes_the_hash(): void
    {
        // The reason is the justification an auditor reads, so a different
        // reason is a different policy.
        $a = $this->policy(fn ($p) => $p->r('orders', 'accounting, 7y'));
        $b = $this->policy(fn ($p) => $p->r('orders', 'accounting, 10y'));

        $this->assertNotSame(PolicyFingerprint::of($a), PolicyFingerprint::of($b));
    }

    #[Test]
    public function no_rules_means_no_fingerprint(): void
    {
        $this->assertNull(PolicyFingerprint::of($this->policy(static fn () => null)));
    }
}
