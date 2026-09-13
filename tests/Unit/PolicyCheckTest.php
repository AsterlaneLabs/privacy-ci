<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Baseline\Baseline;
use PrivacyCI\Baseline\BaselineEntry;
use PrivacyCI\Check\PolicyCheck;
use PrivacyCI\Manifest\Classification;
use PrivacyCI\Manifest\Linkage;
use PrivacyCI\Manifest\Location;
use PrivacyCI\Manifest\LocationKind;
use PrivacyCI\Manifest\Manifest;
use PrivacyCI\Manifest\Subject;

final class PolicyCheckTest extends TestCase
{
    private function location(
        string $path,
        Linkage $linkage = Linkage::ForeignKey,
        Classification $classification = Classification::Unclassified,
    ): Location {
        return new Location(
            id: Location::idFor(LocationKind::DatabaseColumn, 'primary', $path),
            kind: LocationKind::DatabaseColumn,
            store: 'primary',
            path: $path,
            subject: 'user',
            linkage: $linkage,
            confidence: $linkage->isDeterministic() ? 1.0 : 0.8,
            classification: $classification,
        );
    }

    private function manifest(Location ...$locations): Manifest
    {
        return new Manifest('acme/api', [new Subject('user', 'users.id')], $locations);
    }

    private function paths(array $locations): array
    {
        return array_map(static fn (Location $l): string => $l->path, $locations);
    }

    #[Test]
    public function a_new_deterministic_finding_fails_the_build(): void
    {
        $result = (new PolicyCheck)->run(
            $this->manifest($this->location('recommendation_events.user_id')),
            Baseline::empty(),
        );

        $this->assertFalse($result->passes());
        $this->assertSame(['recommendation_events.user_id'], $this->paths($result->violations));
    }

    #[Test]
    public function a_heuristic_finding_only_ever_warns(): void
    {
        $result = (new PolicyCheck)->run(
            $this->manifest($this->location('orders.shipping_address', Linkage::Heuristic)),
            Baseline::empty(),
        );

        $this->assertTrue($result->passes(), 'a name match must never block a deploy');
        $this->assertSame(['orders.shipping_address'], $this->paths($result->warnings));
    }

    #[Test]
    public function an_inferred_finding_only_ever_warns(): void
    {
        $result = (new PolicyCheck)->run(
            $this->manifest($this->location('cache.profile', Linkage::Inferred)),
            Baseline::empty(),
        );

        $this->assertTrue($result->passes());
    }

    #[Test]
    public function a_classified_finding_is_silent(): void
    {
        $result = (new PolicyCheck)->run(
            $this->manifest($this->location('users.id', Linkage::SubjectRoot, Classification::Delete)),
            Baseline::empty(),
        );

        $this->assertTrue($result->passes());
        $this->assertFalse($result->hasAnythingToSay());
    }

    #[Test]
    public function baselined_debt_warns_instead_of_failing(): void
    {
        $manifest = $this->manifest(
            $this->location('comments.user_id'),
            $this->location('orders.buyer_id'),
        );

        $result = (new PolicyCheck)->run($manifest, Baseline::from($manifest));

        $this->assertTrue($result->passes(), 'day one must not be three hundred failures');
        $this->assertCount(2, $result->grandfathered);
        $this->assertSame([], $result->violations);
    }

    #[Test]
    public function a_new_finding_still_fails_alongside_a_baseline(): void
    {
        $existing = $this->manifest($this->location('comments.user_id'));
        $baseline = Baseline::from($existing);

        $result = (new PolicyCheck)->run(
            $this->manifest(
                $this->location('comments.user_id'),
                $this->location('recommendation_events.user_id'),
            ),
            $baseline,
        );

        $this->assertFalse($result->passes());
        $this->assertSame(['recommendation_events.user_id'], $this->paths($result->violations));
        $this->assertSame(['comments.user_id'], $this->paths($result->grandfathered));
    }

    #[Test]
    public function classifying_a_baselined_finding_removes_it_from_both_lists(): void
    {
        $manifest = $this->manifest($this->location('comments.user_id'));
        $baseline = Baseline::from($manifest);

        $result = (new PolicyCheck)->run(
            $this->manifest($this->location(
                'comments.user_id',
                Linkage::ForeignKey,
                Classification::Anonymize,
            )),
            $baseline,
        );

        $this->assertSame([], $result->grandfathered);
        $this->assertSame([], $result->violations);
    }

    #[Test]
    public function a_baselined_warning_is_counted_not_relisted(): void
    {
        $manifest = $this->manifest($this->location('lookup_countries.name', Linkage::Heuristic));
        $result = (new PolicyCheck)->run($manifest, Baseline::from($manifest));

        // Relisting reviewed findings every run is how a hundred-line report
        // trains people to skip the section entirely.
        $this->assertSame([], $result->warnings);
        $this->assertCount(1, $result->seenWarnings);
        $this->assertTrue($result->passes());
    }

    #[Test]
    public function a_new_warning_is_still_listed_alongside_reviewed_ones(): void
    {
        $existing = $this->manifest($this->location('lookup_countries.name', Linkage::Heuristic));
        $baseline = Baseline::from($existing);

        $result = (new PolicyCheck)->run(
            $this->manifest(
                $this->location('lookup_countries.name', Linkage::Heuristic),
                $this->location('profiles.bio', Linkage::Heuristic),
            ),
            $baseline,
        );

        $this->assertSame(['profiles.bio'], $this->paths($result->warnings));
        $this->assertSame(['lookup_countries.name'], $this->paths($result->seenWarnings));
    }

    #[Test]
    public function it_reports_baseline_entries_that_no_longer_exist(): void
    {
        $baseline = new Baseline([
            new BaselineEntry('db:primary:legacy.user_id', 'legacy.user_id', 'foreign_key'),
        ]);

        $result = (new PolicyCheck)->run(
            $this->manifest($this->location('comments.user_id')),
            $baseline,
        );

        // Leaving it in would silently grandfather a future column of the same name.
        $this->assertCount(1, $result->stale);
        $this->assertSame('legacy.user_id', $result->stale[0]->path);
    }

    #[Test]
    public function the_baseline_round_trips_through_json(): void
    {
        $manifest = $this->manifest(
            $this->location('comments.user_id'),
            $this->location('users.email', Linkage::Heuristic),
        );

        $restored = Baseline::fromJson(Baseline::from($manifest, '2026-01-01T00:00:00Z')->toJson());

        $this->assertSame(2, $restored->count());
        $this->assertTrue($restored->covers('db:primary:comments.user_id'));
        $this->assertSame('users.email', $restored->entry('db:primary:users.email')?->path);
    }

    #[Test]
    public function the_baseline_file_is_ordered_so_diffs_stay_readable(): void
    {
        $one = new Baseline([
            new BaselineEntry('db:primary:b.y', 'b.y', 'foreign_key'),
            new BaselineEntry('db:primary:a.x', 'a.x', 'foreign_key'),
        ]);
        $two = new Baseline([
            new BaselineEntry('db:primary:a.x', 'a.x', 'foreign_key'),
            new BaselineEntry('db:primary:b.y', 'b.y', 'foreign_key'),
        ]);

        $this->assertSame($one->toJson(), $two->toJson());
    }

    #[Test]
    public function a_missing_baseline_file_is_simply_empty(): void
    {
        $this->assertTrue(Baseline::load('/nonexistent/privacy-baseline.json')->isEmpty());
    }
}
