<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Manifest\Classification;
use PrivacyCI\Manifest\Integration;
use PrivacyCI\Manifest\Linkage;
use PrivacyCI\Manifest\Location;
use PrivacyCI\Manifest\LocationKind;
use PrivacyCI\Manifest\Manifest;
use PrivacyCI\Manifest\Subject;
use PrivacyCI\Reporting\ConsoleReport;

final class ConsoleReportTest extends TestCase
{
    /** @param list<Location> $locations */
    private function render(array $locations, bool $ansi = false): string
    {
        return (new ConsoleReport($ansi))->render(new Manifest(
            project: 'x',
            subjects: [new Subject('user', 'users.id')],
            locations: $locations,
        ));
    }

    private function location(LocationKind $kind, string $store, string $path): Location
    {
        return new Location(
            id: Location::idFor($kind, $store, $path),
            kind: $kind,
            store: $store,
            path: $path,
            subject: 'user',
            linkage: Linkage::SubjectRoot,
            confidence: 1.0,
            classification: Classification::Delete,
        );
    }

    #[Test]
    public function every_row_says_where_the_data_lives(): void
    {
        // Two indexes named users/{id} are different places depending on which
        // cluster backs Scout, and the answer used to exist only in --json.
        $report = $this->render([
            $this->location(LocationKind::DatabaseColumn, 'primary', 'users.email'),
            $this->location(LocationKind::SearchIndex, 'opensearch', 'users/{id}'),
            $this->location(LocationKind::ObjectStorage, 's3', 'avatars/{id}.jpg'),
        ]);

        $this->assertMatchesRegularExpression('/users\.email\s+primary\s+subject root/', $report);
        $this->assertMatchesRegularExpression('/users\/\{id\}\s+opensearch\s+subject root/', $report);
        $this->assertMatchesRegularExpression('/avatars\/\{id\}\.jpg\s+s3\s+subject root/', $report);
    }

    #[Test]
    public function the_columns_are_named(): void
    {
        // `default` and `scout` are meaningless without a label, and a column
        // nobody can name is a column nobody reads.
        $report = $this->render([
            $this->location(LocationKind::SearchIndex, 'scout', 'users/{id}'),
        ]);

        $header = '';

        foreach (explode("\n", $report) as $line) {
            if (str_contains($line, 'location') && str_contains($line, 'store')) {
                $header = $line;
                break;
            }
        }

        $this->assertNotSame('', $header, 'the report names its columns');

        // The manifest's own vocabulary, so the report and --json describe the
        // same finding the same way.
        foreach (['location', 'store', 'linkage', 'confidence', 'classification'] as $column) {
            $this->assertStringContainsString($column, $header);
        }

        // Headed by the same widths as the rows it sits above.
        $row = '';

        foreach (explode("\n", $report) as $line) {
            if (str_contains($line, 'users/{id}')) {
                $row = $line;
                break;
            }
        }

        $this->assertSame(strpos($header, 'store'), strpos($row, 'scout'));
    }

    #[Test]
    public function the_store_column_lines_up_across_sections(): void
    {
        // Columns that shift between the confidence sections read as three
        // unrelated tables rather than one report.
        $high = $this->location(LocationKind::SearchIndex, 'opensearch', 'users/{id}');
        $weak = new Location(
            id: 'db:primary:comments.body',
            kind: LocationKind::DatabaseColumn,
            store: 'primary',
            path: 'comments.body',
            subject: 'user',
            linkage: Linkage::Heuristic,
            confidence: 0.40,
        );

        $columns = [];

        foreach (explode("\n", $this->render([$high, $weak])) as $line) {
            foreach (['opensearch', 'primary'] as $store) {
                $at = strpos($line, $store);

                if ($at !== false) {
                    $columns[] = $at;
                }
            }
        }

        $this->assertCount(2, $columns);
        $this->assertCount(1, array_unique($columns));
    }

    #[Test]
    public function the_primary_database_recedes_and_the_rest_does_not(): void
    {
        // Dimmed rather than blank: a hole on four rows in five makes a ragged
        // column out of the one being scanned to answer "where does this live?".
        $report = $this->render([
            $this->location(LocationKind::DatabaseColumn, 'primary', 'users.email'),
            $this->location(LocationKind::SearchIndex, 'opensearch', 'users/{id}'),
        ], ansi: true);

        $this->assertStringContainsString("\033[2mprimary", $report);
        $this->assertStringNotContainsString("\033[2mopensearch", $report);
    }

    #[Test]
    public function a_cluster_we_mapped_nothing_in_says_so(): void
    {
        // Silence here reads as a clean result and is usually a miss: a cluster
        // is in composer.lock because something writes to it. Found on a real
        // application whose OpenSearch code sat outside discovery.source_paths.
        $report = (new ConsoleReport(ansi: false))->render(new Manifest(
            project: 'x',
            subjects: [new Subject('user', 'users.id')],
            locations: [$this->location(LocationKind::DatabaseColumn, 'primary', 'users.email')],
            integrations: [new Integration('OpenSearch', 'opensearch-project/opensearch-php', true)],
        ));

        $this->assertStringContainsString('no indexed personal data was found', $report);
        $this->assertStringContainsString('discovery.source_paths', $report);
    }

    #[Test]
    public function a_cluster_we_did_map_does_not_nag(): void
    {
        $report = (new ConsoleReport(ansi: false))->render(new Manifest(
            project: 'x',
            subjects: [new Subject('user', 'users.id')],
            locations: [$this->location(LocationKind::SearchIndex, 'opensearch', 'users/{id}')],
            integrations: [new Integration('OpenSearch', 'opensearch-project/opensearch-php', true)],
        ));

        $this->assertStringNotContainsString('no indexed personal data was found', $report);
    }

    #[Test]
    public function a_long_package_name_does_not_push_the_last_column_out(): void
    {
        // 'opensearch-project/opensearch-php' is longer than the fixed width the
        // column used to assume, so the row a reader most wants to scan was the
        // one that came out ragged.
        $report = (new ConsoleReport(ansi: false))->render(new Manifest(
            project: 'x',
            subjects: [new Subject('user', 'users.id')],
            locations: [],
            integrations: [
                new Integration('OpenSearch', 'opensearch-project/opensearch-php', true),
                new Integration('S3', 'config/filesystems.php', true),
                new Integration('Snowflake', 'config/services.php', false),
            ],
        ));

        $columns = [];

        foreach (explode("\n", $report) as $line) {
            foreach (['scannable', 'not yet scannable'] as $label) {
                $at = strpos($line, $label);

                // 'scannable' is a substring of 'not yet scannable'; only the
                // start of the final column counts.
                if ($at !== false && ! str_contains(substr($line, 0, $at), 'not yet')) {
                    $columns[] = $at;
                    break;
                }
            }
        }

        $this->assertCount(3, $columns);
        $this->assertCount(1, array_unique($columns), 'every row starts its last column in the same place');
    }
}
