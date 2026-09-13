<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Discovery\Discoverer;
use PrivacyCI\Discovery\Scanners\ModelScanner;
use PrivacyCI\Manifest\LocationKind;
use PrivacyCI\Manifest\Subject;
use PrivacyCI\Policy\PolicyCompiler;
use PrivacyCI\Verification\Address;
use PrivacyCI\Verification\Expectation;
use PrivacyCI\Verification\Footprint;
use PrivacyCI\Verification\FootprintResolver;
use PrivacyCI\Verification\Outcome;
use PrivacyCI\Verification\Probe;
use PrivacyCI\Verification\Verifier;

final class VerificationTest extends TestCase
{
    private Subject $subject;

    protected function setUp(): void
    {
        require_once __DIR__.'/../fixtures/Policies/UserPrivacyPolicy.php';
        $this->subject = new Subject('user', 'users.id');
    }

    private function footprint(string $id = '99'): Footprint
    {
        $models = (new ModelScanner)->scan([__DIR__.'/../fixtures/Models']);

        $manifest = (new Discoverer)->discover(
            project: 'fixture/app',
            migrationPaths: [__DIR__.'/../fixtures/migrations'],
            subject: $this->subject,
            modelPaths: [__DIR__.'/../fixtures/Models'],
        );

        $manifest = (new PolicyCompiler($models))->apply($manifest, new \App\Privacy\UserPrivacyPolicy);

        return (new FootprintResolver)->resolve($manifest, $this->subject, $id, '2026-01-01T00:00:00Z');
    }

    /** @param array<string, bool> $present describe => still there */
    private function probe(array $present, ?callable $onCheck = null): Probe
    {
        return new class($present, $onCheck) implements Probe
        {
            public array $checked = [];

            public function __construct(private array $present, private $onCheck)
            {
            }

            public function handles(LocationKind $kind): bool
            {
                return true;
            }

            public function exists(Address $address): bool
            {
                if ($this->onCheck !== null) {
                    ($this->onCheck)($address);
                }

                $this->checked[] = $address->describe;

                return $this->present[$address->describe] ?? false;
            }
        };
    }

    private function describes(Footprint $f): array
    {
        return array_map(static fn (Address $a): string => $a->describe, $f->addresses);
    }

    #[Test]
    public function it_resolves_patterns_against_a_specific_subject(): void
    {
        $describes = $this->describes($this->footprint('99'));

        $this->assertContains('profile:99', $describes, 'the Redis pattern resolves');
        $this->assertContains('avatars/99.jpg', $describes, 'the storage path resolves');
        $this->assertContains('users where id = 99', $describes);
        $this->assertContains('comments where user_id = 99', $describes);
    }

    #[Test]
    public function anonymise_and_delete_assert_the_same_thing(): void
    {
        foreach ($this->footprint()->addresses as $address) {
            if ($address->describe === 'comments where user_id = 99') {
                // Anonymising nulls the link, so afterwards nothing matches the
                // id, exactly what deletion asserts.
                $this->assertSame(Expectation::Absent, $address->expectation);

                return;
            }
        }

        $this->fail('comments address not found');
    }

    #[Test]
    public function retained_tables_expect_presence(): void
    {
        foreach ($this->footprint()->addresses as $address) {
            if (str_starts_with($address->describe, 'orders')) {
                $this->assertSame(Expectation::Retained, $address->expectation);
                $this->assertSame('statutory accounting retention, 7y', $address->reason);

                return;
            }
        }

        $this->fail('orders address not found');
    }

    #[Test]
    public function data_with_no_link_to_the_subject_is_marked_unverifiable(): void
    {
        $unverifiable = $this->footprint()->unverifiable();

        $describes = array_map(static fn (Address $a): string => $a->describe, $unverifiable);

        // subscribers.email is personal data with no foreign key to users.
        $this->assertContains('subscribers', $describes);

        foreach ($unverifiable as $address) {
            $this->assertNotNull($address->reason, 'an unverifiable address must explain itself');
        }
    }

    #[Test]
    public function a_clean_deletion_passes(): void
    {
        $result = (new Verifier([$this->probe([])]))->verify($this->footprint());

        $this->assertTrue($result->passed());
        $this->assertGreaterThan(0, $result->count(Outcome::Pass));
    }

    #[Test]
    public function remaining_data_fails(): void
    {
        $probe = $this->probe(['avatars/99.jpg' => true]);
        $result = (new Verifier([$probe]))->verify($this->footprint());

        $this->assertFalse($result->passed());

        $failures = array_map(
            static fn ($r): string => $r->address->describe,
            $result->of(Outcome::Fail),
        );

        $this->assertSame(['avatars/99.jpg'], $failures);
    }

    #[Test]
    public function a_store_with_no_probe_is_unchecked_not_passed(): void
    {
        $narrow = new class implements Probe
        {
            public function handles(LocationKind $kind): bool
            {
                return $kind === LocationKind::DatabaseColumn;
            }

            public function exists(Address $address): bool
            {
                return false;
            }
        };

        $result = (new Verifier([$narrow]))->verify($this->footprint());

        $unchecked = array_map(
            static fn ($r): string => $r->address->describe,
            $result->of(Outcome::Unchecked),
        );

        // Redis and storage had no probe. Reporting them as passes would claim
        // we erased a subject from stores nobody looked in.
        $this->assertContains('profile:99', $unchecked);
        $this->assertContains('avatars/99.jpg', $unchecked);
        $this->assertFalse($result->complete());
        $this->assertTrue($result->passed(), 'unchecked is not a failure either');
    }

    #[Test]
    public function a_probe_that_throws_is_unchecked_not_passed(): void
    {
        $broken = $this->probe([], static function (Address $a): void {
            if ($a->kind === LocationKind::RedisKey) {
                throw new \RuntimeException('connection refused');
            }
        });

        $result = (new Verifier([$broken]))->verify($this->footprint());

        $unchecked = $result->of(Outcome::Unchecked);
        $messages = array_map(static fn ($r): string => (string) $r->detail, $unchecked);

        $this->assertNotEmpty(array_filter(
            $messages,
            static fn (string $m): bool => str_contains($m, 'connection refused'),
        ));
        $this->assertFalse($result->complete());
    }

    #[Test]
    public function one_broken_store_does_not_stop_the_others_being_checked(): void
    {
        $probe = $this->probe([], static function (Address $a): void {
            if ($a->kind === LocationKind::RedisKey) {
                throw new \RuntimeException('down');
            }
        });

        $result = (new Verifier([$probe]))->verify($this->footprint());

        $this->assertGreaterThan(0, $result->count(Outcome::Pass));
    }

    #[Test]
    public function the_footprint_round_trips_through_json(): void
    {
        $original = $this->footprint();
        $restored = Footprint::fromJson($original->toJson());

        $this->assertSame($original->toJson(), $restored->toJson());
        $this->assertSame($this->describes($original), $this->describes($restored));
    }

    #[Test]
    public function the_evidence_fingerprint_ignores_when_it_ran(): void
    {
        $verifier = new Verifier([$this->probe([])]);
        $footprint = $this->footprint();

        $one = $verifier->verify($footprint, '2026-01-01T00:00:00Z');
        $two = $verifier->verify($footprint, '2026-06-01T00:00:00Z');

        $this->assertSame($one->fingerprint(), $two->fingerprint());
    }

    #[Test]
    public function a_different_subject_resolves_to_different_addresses(): void
    {
        $this->assertContains('profile:7', $this->describes($this->footprint('7')));
        $this->assertNotContains('profile:99', $this->describes($this->footprint('7')));
    }
}
