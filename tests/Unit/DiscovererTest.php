<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Discovery\Discoverer;
use PrivacyCI\Manifest\Linkage;
use PrivacyCI\Manifest\Manifest;

final class DiscovererTest extends TestCase
{
    private function discover(): Manifest
    {
        return (new Discoverer)->discover(
            project: 'fixture/app',
            migrationPaths: [__DIR__.'/../fixtures/migrations'],
        );
    }

    private function discoverWithModels(): Manifest
    {
        return (new Discoverer)->discover(
            project: 'fixture/app',
            migrationPaths: [__DIR__.'/../fixtures/migrations'],
            modelPaths: [__DIR__.'/../fixtures/Models'],
        );
    }

    private function ids(Manifest $manifest): array
    {
        return array_map(static fn ($l): string => $l->path, $manifest->locations());
    }

    #[Test]
    public function it_finds_the_subject_root(): void
    {
        $location = $this->discover()->location('db:primary:users.id');

        $this->assertNotNull($location);
        $this->assertSame(Linkage::SubjectRoot, $location->linkage);
        $this->assertSame(1.0, $location->confidence);
    }

    #[Test]
    public function it_finds_foreign_keys_with_full_confidence(): void
    {
        $manifest = $this->discover();

        foreach (['comments.user_id', 'orders.buyer_id', 'recommendation_events.user_id'] as $path) {
            $location = $manifest->location("db:primary:{$path}");

            $this->assertNotNull($location, "{$path} should be discovered");
            $this->assertSame(Linkage::ForeignKey, $location->linkage, "{$path} linkage");
            $this->assertSame(1.0, $location->confidence, "{$path} confidence");
        }
    }

    #[Test]
    public function it_finds_personal_columns_with_no_foreign_key(): void
    {
        $paths = $this->ids($this->discover());

        // The hard case the plan calls out: PII that no foreign key points at.
        $this->assertContains('users.email', $paths);
        $this->assertContains('users.phone', $paths);
        $this->assertContains('orders.shipping_address', $paths);
        $this->assertContains('comments.author_ip', $paths);
        $this->assertContains('recommendation_events.device_id', $paths);
        $this->assertContains('sessions.ip_address', $paths);
    }

    #[Test]
    public function it_ignores_structural_columns(): void
    {
        $paths = $this->ids($this->discover());

        foreach (['users.created_at', 'users.updated_at', 'orders.total', 'comments.id'] as $path) {
            $this->assertNotContains($path, $paths, "{$path} is not personal data");
        }
    }

    #[Test]
    public function email_verified_at_is_excluded_despite_containing_email(): void
    {
        $this->assertNotContains('users.email_verified_at', $this->ids($this->discover()));
    }

    #[Test]
    public function heuristic_findings_never_block_a_build(): void
    {
        $manifest = $this->discover();

        foreach ($manifest->locations() as $location) {
            if ($location->linkage === Linkage::Heuristic) {
                $this->assertFalse(
                    $location->blocksBuild(),
                    "{$location->path} is heuristic and must only warn",
                );
            }
        }

        // But deterministic ones do.
        $this->assertNotEmpty($manifest->blocking());
    }

    #[Test]
    public function everything_starts_unclassified(): void
    {
        $manifest = $this->discover();

        $this->assertCount(count($manifest->locations()), $manifest->unclassified());
    }

    #[Test]
    public function only_deterministic_findings_reach_full_confidence(): void
    {
        foreach ($this->discover()->locations() as $location) {
            if ($location->linkage === Linkage::Heuristic) {
                $this->assertLessThan(
                    1.0,
                    $location->confidence,
                    "{$location->path}: a name match is never certain",
                );
            }
        }
    }

    #[Test]
    public function relationships_reveal_columns_no_migration_declares(): void
    {
        // audit_entries has no migration at all; only AuditEntry::actor() knows
        // that actor_id is a person.
        $location = $this->discoverWithModels()->location('db:primary:audit_entries.actor_id');

        $this->assertNotNull($location, 'a relationship-only table must still be mapped');
        $this->assertSame(Linkage::Relationship, $location->linkage);
        $this->assertTrue($location->blocksBuild(), 'a declared relationship is deterministic');
    }

    #[Test]
    public function relationship_findings_are_absent_without_model_paths(): void
    {
        $this->assertNull(
            $this->discover()->location('db:primary:audit_entries.actor_id'),
            'migrations alone cannot see this association',
        );
    }

    #[Test]
    public function hidden_or_encrypted_columns_gain_confidence(): void
    {
        $without = $this->discover()->location('db:primary:users.password');
        $with = $this->discoverWithModels()->location('db:primary:users.password');

        $this->assertNotNull($without);
        $this->assertNotNull($with);
        $this->assertGreaterThan(
            $without->confidence,
            $with->confidence,
            '$hidden should raise confidence on a name match',
        );
        $this->assertContains('marked $hidden or encrypted by its model', $with->evidence);
    }

    #[Test]
    public function a_foreign_key_still_wins_over_a_relationship(): void
    {
        // comments.user_id has both a constraint and a belongsTo; the constraint
        // is the stronger evidence and should be what we report.
        $location = $this->discoverWithModels()->location('db:primary:comments.user_id');

        $this->assertSame(Linkage::ForeignKey, $location?->linkage);
    }

    #[Test]
    public function static_analysis_finds_stores_the_schema_cannot_see(): void
    {
        $manifest = (new Discoverer)->discover(
            project: 'fixture/app',
            migrationPaths: [__DIR__.'/../fixtures/migrations'],
            sourcePaths: [__DIR__.'/../fixtures/Flow'],
        );

        $redis = $manifest->location('redis:cache:user:{userId}');
        $s3 = $manifest->location('storage:s3:avatars/{user.id}.jpg');

        $this->assertNotNull($redis, 'a Redis key no migration declares');
        $this->assertNotNull($s3, 'an S3 path no migration declares');
        $this->assertSame(Linkage::Inferred, $redis->linkage);
    }

    #[Test]
    public function inferred_findings_can_never_fail_a_build(): void
    {
        $manifest = (new Discoverer)->discover(
            project: 'fixture/app',
            migrationPaths: [__DIR__.'/../fixtures/migrations'],
            sourcePaths: [__DIR__.'/../fixtures/Flow'],
        );

        foreach ($manifest->locations() as $location) {
            if ($location->linkage === Linkage::Inferred) {
                $this->assertFalse(
                    $location->blocksBuild(),
                    "{$location->path}: static analysis may only ever warn",
                );
            }
        }
    }

    #[Test]
    public function static_analysis_is_absent_without_source_paths(): void
    {
        $this->assertNull(
            $this->discover()->location('redis:cache:user:{userId}'),
            'Stage B must be opt-in',
        );
    }

    #[Test]
    public function a_generic_word_on_an_unlinked_table_is_damped_away(): void
    {
        $paths = $this->ids($this->discover());

        // subscribers has no route to users, so `source` is not reported:
        // a generic word on an unlinked table is almost never personal data.
        $this->assertNotContains('subscribers.source', $paths);

        // But an unambiguous identifier on the same unlinked table survives.
        $this->assertContains('subscribers.email', $paths);
    }

    #[Test]
    public function a_generic_word_on_a_linked_table_is_kept(): void
    {
        $paths = $this->ids($this->discover());

        // comments is reachable from users, so free text there stays visible.
        $this->assertContains('comments.body', $paths);
    }

    #[Test]
    public function a_declared_relationship_links_a_table_with_no_constraint(): void
    {
        // audit_entries has no migration and no foreign key. Only
        // AuditEntry::actor() ties actor_id to a person, and that is
        // deterministic enough to fail a build on.
        $location = $this->discoverWithModels()->location('db:primary:audit_entries.actor_id');

        $this->assertNotNull($location);
        $this->assertSame(Linkage::Relationship, $location->linkage);
        $this->assertTrue($location->blocksBuild());
        $this->assertContains('no database foreign key required', $location->evidence);
    }

    #[Test]
    public function a_prescanned_model_map_is_used_instead_of_rescanning(): void
    {
        $models = (new \PrivacyCI\Discovery\Scanners\ModelScanner)
            ->scan([__DIR__.'/../fixtures/Models']);

        $manifest = (new Discoverer)->discover(
            project: 'fixture/app',
            migrationPaths: [__DIR__.'/../fixtures/migrations'],
            models: $models,
        );

        $this->assertNotNull(
            $manifest->location('db:primary:audit_entries.actor_id'),
            'supplying models should work without modelPaths',
        );
    }

    #[Test]
    public function it_records_evidence_for_every_finding(): void
    {
        foreach ($this->discover()->locations() as $location) {
            $this->assertNotEmpty($location->evidence, "{$location->path} needs evidence");
        }
    }
}
