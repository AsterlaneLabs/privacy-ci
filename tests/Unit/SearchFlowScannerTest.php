<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Discovery\Flow\FlowFinding;
use PrivacyCI\Discovery\Scanners\SearchFlowScanner;
use PrivacyCI\Manifest\LocationKind;
use PrivacyCI\Manifest\Subject;

/**
 * Documents written through the SDK rather than through Scout.
 *
 * Scout is the tidy case and not the common one, so this is the path that
 * decides whether the feature finds anything in a real application.
 */
final class SearchFlowScannerTest extends TestCase
{
    /** @return list<FlowFinding> */
    private function scan(string $store = 'opensearch'): array
    {
        return (new SearchFlowScanner)->scan(
            [__DIR__.'/../fixtures/SearchFlow'],
            new Subject('user', 'users.id'),
            $store,
        );
    }

    /** @return list<string> */
    private function patterns(): array
    {
        return array_map(static fn (FlowFinding $f): string => $f->pattern, $this->scan());
    }

    #[Test]
    public function it_follows_a_request_built_a_key_at_a_time(): void
    {
        // Matching on the ->index() call finds nothing here: the array is built
        // in one method and sent from another, often in another file. The
        // request *shape* is the thing that cannot be avoided.
        $this->assertContains('{index}/{id}', $this->patterns());
    }

    #[Test]
    public function it_names_the_columns_that_left_the_database(): void
    {
        foreach ($this->scan() as $finding) {
            if ($finding->pattern !== '{index}/{id}') {
                continue;
            }

            $evidence = implode("\n", $finding->evidence);

            $this->assertStringContainsString('document id is user.user_id', $evidence);
            $this->assertStringContainsString('username', $evidence);
            $this->assertStringContainsString('inferred by static analysis', $evidence);

            return;
        }

        $this->fail('the assembled request was not found');
    }

    #[Test]
    public function a_write_call_marks_a_literal_even_with_no_engine_named(): void
    {
        $this->assertContains('members/{id}', $this->patterns());
    }

    #[Test]
    public function an_id_with_no_index_is_not_an_index_request(): void
    {
        // Found on a real codebase: getRouteParameters() returning
        // ['id' => $model['user_id'], 'username' => ...] for a URL. Telling a
        // team they have an unmapped search index when they have a route is
        // exactly the false positive that makes people stop reading the report.
        foreach ($this->scan() as $finding) {
            $this->assertStringNotContainsString(
                'RouteParams',
                implode("\n", $finding->evidence),
                'a route parameter array is not a document',
            );
        }
    }

    #[Test]
    public function a_document_keyed_by_something_other_than_a_person_is_ignored(): void
    {
        $this->assertNotContains('orders/{id}', $this->patterns());
    }

    #[Test]
    public function an_array_in_a_file_about_nothing_in_particular_is_ignored(): void
    {
        // Unrelated.php has the exact shape and neither an engine name nor a
        // write call. $params['id'] is too common to flag on its own.
        foreach ($this->scan() as $finding) {
            $this->assertStringNotContainsString('Unrelated', implode("\n", $finding->evidence));
        }
    }

    #[Test]
    public function every_finding_is_a_search_index_on_the_detected_cluster(): void
    {
        $findings = $this->scan();

        $this->assertNotEmpty($findings);

        foreach ($findings as $finding) {
            $this->assertSame(LocationKind::SearchIndex, $finding->kind);
            $this->assertSame('opensearch', $finding->store);
        }
    }

    #[Test]
    public function nothing_here_can_ever_fail_a_build(): void
    {
        // Static analysis of a request shape is a guess, however good it looks.
        foreach ($this->scan() as $finding) {
            $this->assertLessThan(1.0, $finding->confidence);
        }
    }
}
