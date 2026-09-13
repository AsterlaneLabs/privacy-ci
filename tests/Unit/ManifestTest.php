<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Manifest\Classification;
use PrivacyCI\Manifest\Linkage;
use PrivacyCI\Manifest\Location;
use PrivacyCI\Manifest\LocationKind;
use PrivacyCI\Manifest\Manifest;
use PrivacyCI\Manifest\Subject;

final class ManifestTest extends TestCase
{
    private function location(string $path, Classification $c = Classification::Unclassified): Location
    {
        return new Location(
            id: Location::idFor(LocationKind::DatabaseColumn, 'primary', $path),
            kind: LocationKind::DatabaseColumn,
            store: 'primary',
            path: $path,
            subject: 'user',
            linkage: Linkage::ForeignKey,
            confidence: 1.0,
            classification: $c,
        );
    }

    private function manifest(Location ...$locations): Manifest
    {
        return new Manifest('acme/api', [new Subject('user', 'users.id')], $locations);
    }

    #[Test]
    public function serialisation_is_deterministic_regardless_of_input_order(): void
    {
        $a = $this->manifest($this->location('b.x'), $this->location('a.y'));
        $b = $this->manifest($this->location('a.y'), $this->location('b.x'));

        $this->assertSame($a->toJson(), $b->toJson());
    }

    #[Test]
    public function fingerprint_ignores_scan_time_and_commit(): void
    {
        $one = new Manifest('acme/api', [], [$this->location('a.b')], [], 'production', 'aaa', '2026-01-01T00:00:00Z');
        $two = new Manifest('acme/api', [], [$this->location('a.b')], [], 'production', 'bbb', '2026-06-01T00:00:00Z');

        $this->assertSame($one->fingerprint(), $two->fingerprint());
    }

    #[Test]
    public function fingerprint_changes_when_a_location_appears(): void
    {
        $before = $this->manifest($this->location('a.b'));
        $after = $this->manifest($this->location('a.b'), $this->location('c.d'));

        $this->assertNotSame($before->fingerprint(), $after->fingerprint());
    }

    #[Test]
    public function diff_reports_only_genuinely_new_locations(): void
    {
        $baseline = $this->manifest($this->location('users.id'), $this->location('comments.user_id'));
        $current = $this->manifest(
            $this->location('users.id'),
            $this->location('comments.user_id'),
            $this->location('recommendation_events.user_id'),
        );

        $diff = $current->diff($baseline);

        $this->assertCount(1, $diff['added']);
        $this->assertSame('recommendation_events.user_id', $diff['added'][0]->path);
        $this->assertEmpty($diff['removed']);
        $this->assertEmpty($diff['reclassified']);
    }

    #[Test]
    public function diff_reports_removals_and_reclassifications(): void
    {
        $baseline = $this->manifest(
            $this->location('users.id'),
            $this->location('orders.buyer_id', Classification::Retain),
        );
        $current = $this->manifest(
            $this->location('orders.buyer_id', Classification::Delete),
        );

        $diff = $current->diff($baseline);

        $this->assertSame(['users.id'], array_map(static fn ($l) => $l->path, $diff['removed']));
        $this->assertSame(['orders.buyer_id'], array_map(static fn ($l) => $l->path, $diff['reclassified']));
    }

    #[Test]
    public function it_round_trips_through_json(): void
    {
        $original = $this->manifest(
            $this->location('users.id'),
            $this->location('comments.user_id', Classification::Anonymize),
        );

        $restored = Manifest::fromJson($original->toJson());

        $this->assertSame($original->toJson(), $restored->toJson());
        $this->assertSame($original->fingerprint(), $restored->fingerprint());
    }

    #[Test]
    public function classified_locations_no_longer_block(): void
    {
        $manifest = $this->manifest(
            $this->location('users.id', Classification::Delete),
            $this->location('comments.user_id'),
        );

        $this->assertCount(1, $manifest->blocking());
        $this->assertSame('comments.user_id', $manifest->blocking()[0]->path);
    }
}
