<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Discovery\Scanners\IntegrationScanner;
use PrivacyCI\Manifest\Integration;

final class IntegrationScannerTest extends TestCase
{
    /** @return list<Integration> */
    private function scan(): array
    {
        return (new IntegrationScanner)->scan([__DIR__.'/../fixtures/config']);
    }

    private function kinds(): array
    {
        return array_map(static fn (Integration $i): string => $i->kind, $this->scan());
    }

    #[Test]
    public function it_detects_stores_from_config(): void
    {
        $kinds = $this->kinds();

        $this->assertContains('Redis', $kinds);
        $this->assertContains('S3', $kinds);
        $this->assertContains('Stripe', $kinds);
    }

    #[Test]
    public function it_marks_warehouses_as_detected_but_not_scannable(): void
    {
        $snowflake = null;

        foreach ($this->scan() as $integration) {
            if ($integration->kind === 'Snowflake') {
                $snowflake = $integration;
            }
        }

        $this->assertNotNull($snowflake, 'Snowflake should be detected');
        $this->assertFalse(
            $snowflake->supported,
            'an unsupported store is the demand signal for the next connector',
        );
    }

    #[Test]
    public function redis_and_s3_are_scannable(): void
    {
        foreach ($this->scan() as $integration) {
            if (in_array($integration->kind, ['Redis', 'S3'], true)) {
                $this->assertTrue($integration->supported, "{$integration->kind} should be scannable");
            }
        }
    }

    #[Test]
    public function it_ignores_markers_that_only_appear_in_comments(): void
    {
        // database.php mentions "snowflake" in a comment; services.php declares it
        // as a real key. Only the latter should count, and it must be attributed
        // to services.php rather than database.php.
        foreach ($this->scan() as $integration) {
            if ($integration->kind === 'Snowflake') {
                $this->assertSame('config/services.php', $integration->detectedFrom);
            }
        }
    }

    /** @return list<string> */
    private function kindsIn(string $dir): array
    {
        return array_map(
            static fn (Integration $i): string => $i->kind,
            (new IntegrationScanner)->scan([__DIR__.'/../fixtures/'.$dir]),
        );
    }

    #[Test]
    public function a_word_in_a_list_does_not_declare_a_store(): void
    {
        // An application with an icon called "snowflake" was being told it had
        // a data warehouse. Telling a team they run infrastructure they do not
        // run is how a report loses its credibility entirely.
        $kinds = $this->kindsIn('config-noise');

        $this->assertNotContains('Stripe', $kinds, 'an icon named "stripe" is not Stripe');
    }

    #[Test]
    public function a_config_key_does_declare_a_store(): void
    {
        $kinds = $this->kindsIn('config-noise');

        // Same directory, same word, but here it is a configured connection,
        // in a config file whose name we could never have guessed.
        $this->assertContains('Snowflake', $kinds);
        $this->assertContains('ClickHouse', $kinds);
    }

    #[Test]
    public function snowflake_is_attributed_to_the_file_that_declares_it(): void
    {
        foreach ((new IntegrationScanner)->scan([__DIR__.'/../fixtures/config-noise']) as $i) {
            if ($i->kind === 'Snowflake') {
                $this->assertSame('config/analytics.php', $i->detectedFrom);
            }
        }
    }

    #[Test]
    public function a_driver_value_declares_a_store(): void
    {
        $kinds = $this->kindsIn('config');

        // 'driver' => 's3' names the thing being configured.
        $this->assertContains('S3', $kinds);
    }

    #[Test]
    public function it_records_where_each_integration_was_found(): void
    {
        foreach ($this->scan() as $integration) {
            $this->assertStringStartsWith('config/', $integration->detectedFrom);
        }
    }
}
