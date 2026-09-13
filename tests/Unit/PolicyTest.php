<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Discovery\Discoverer;
use PrivacyCI\Discovery\Scanners\ModelScanner;
use PrivacyCI\Manifest\Classification;
use PrivacyCI\Manifest\Linkage;
use PrivacyCI\Manifest\LocationKind;
use PrivacyCI\Manifest\Manifest;
use PrivacyCI\Policy\InvalidPolicy;
use PrivacyCI\Policy\PolicyCompiler;
use PrivacyCI\Policy\PrivacyPolicy;

final class PolicyTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__.'/../fixtures/Policies/UserPrivacyPolicy.php';
    }

    private function compiled(): Manifest
    {
        $manifest = (new Discoverer)->discover(
            project: 'fixture/app',
            migrationPaths: [__DIR__.'/../fixtures/migrations'],
            modelPaths: [__DIR__.'/../fixtures/Models'],
        );

        $models = (new ModelScanner)->scan([__DIR__.'/../fixtures/Models']);

        return (new PolicyCompiler($models))->apply($manifest, new \App\Privacy\UserPrivacyPolicy);
    }

    #[Test]
    public function a_table_rule_classifies_every_column_on_that_table(): void
    {
        $manifest = $this->compiled();

        foreach (['users.id', 'users.email', 'users.phone'] as $path) {
            $this->assertSame(
                Classification::Delete,
                $manifest->location("db:primary:{$path}")?->classification,
                $path,
            );
        }
    }

    #[Test]
    public function a_column_rule_narrows_a_table_rule(): void
    {
        $manifest = $this->compiled();

        $this->assertSame(
            Classification::Anonymize,
            $manifest->location('db:primary:comments.user_id')?->classification,
        );
        // comments.body is not named, so it stays unclassified.
        $this->assertSame(
            Classification::Unclassified,
            $manifest->location('db:primary:comments.body')?->classification,
        );
    }

    #[Test]
    public function retain_carries_its_reason_into_the_manifest(): void
    {
        $location = $this->compiled()->location('db:primary:orders.buyer_id');

        $this->assertSame(Classification::Retain, $location?->classification);
        $this->assertSame('statutory accounting retention, 7y', $location?->reason);
    }

    #[Test]
    public function every_classified_location_points_at_the_policy_line(): void
    {
        foreach ($this->compiled()->locations() as $location) {
            if ($location->classification->isResolved()) {
                $this->assertNotNull($location->policySource, $location->path);
                $this->assertStringStartsWith('UserPrivacyPolicy.php:', $location->policySource);
            }
        }
    }

    #[Test]
    public function store_rules_add_locations_discovery_cannot_reach(): void
    {
        $manifest = $this->compiled();

        $redis = $manifest->location('redis:default:profile:{id}');
        $this->assertNotNull($redis, 'the policy declares a Redis key');
        $this->assertSame(LocationKind::RedisKey, $redis->kind);
        $this->assertSame(Linkage::Declared, $redis->linkage);

        $s3 = $manifest->location('storage:s3:avatars/{id}.jpg');
        $this->assertNotNull($s3, 'the policy declares an S3 object');
    }

    #[Test]
    public function classifying_a_location_stops_it_blocking_the_build(): void
    {
        $before = (new Discoverer)->discover(
            project: 'fixture/app',
            migrationPaths: [__DIR__.'/../fixtures/migrations'],
            modelPaths: [__DIR__.'/../fixtures/Models'],
        );

        $this->assertNotEmpty($before->blocking());

        $after = $this->compiled();

        // audit_entries and recommendation_events are still unclassified.
        $paths = array_map(static fn ($l): string => $l->path, $after->blocking());

        $this->assertNotContains('users.id', $paths);
        $this->assertNotContains('comments.user_id', $paths);
        $this->assertContains('recommendation_events.user_id', $paths);
    }

    #[Test]
    public function retain_without_a_reason_is_rejected(): void
    {
        $policy = new class extends PrivacyPolicy
        {
            public function configure(): void
            {
                $this->retain('orders');
            }
        };

        $this->expectException(InvalidPolicy::class);
        $this->expectExceptionMessageMatches('/needs a documented reason/');

        $policy->rules();
    }

    #[Test]
    public function custom_without_a_handler_is_rejected(): void
    {
        $policy = new class extends PrivacyPolicy
        {
            public function configure(): void
            {
                $this->custom('orders', '');
            }
        };

        $this->expectException(InvalidPolicy::class);
        $this->expectExceptionMessageMatches('/needs a handler class/');

        $policy->rules();
    }
}
