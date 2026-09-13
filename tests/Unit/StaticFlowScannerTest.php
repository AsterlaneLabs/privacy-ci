<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Discovery\Flow\FlowFinding;
use PrivacyCI\Discovery\Scanners\StaticFlowScanner;
use PrivacyCI\Manifest\LocationKind;
use PrivacyCI\Manifest\Subject;

final class StaticFlowScannerTest extends TestCase
{
    /** @return list<FlowFinding> */
    private function scan(): array
    {
        return (new StaticFlowScanner)->scan(
            [__DIR__.'/../fixtures/Flow'],
            new Subject('user', 'users.id'),
        );
    }

    /** @return list<string> */
    private function patterns(): array
    {
        return array_map(static fn (FlowFinding $f): string => $f->pattern, $this->scan());
    }

    #[Test]
    public function the_subject_placeholder_is_normalised(): void
    {
        // The policy declares `user:{id}`; the code writes `user:{$userId}`.
        // If those produce two locations, one key has to be classified twice.
        $this->assertContains('user:{id}', $this->patterns());
        $this->assertNotContains('user:{userId}', $this->patterns());
    }

    #[Test]
    public function it_finds_an_interpolated_cache_key(): void
    {
        $this->assertContains('user:{id}', $this->patterns());
    }

    #[Test]
    public function it_finds_object_storage_paths(): void
    {
        $this->assertContains('avatars/{id}.jpg', $this->patterns());
    }

    #[Test]
    public function it_handles_concatenation_as_well_as_interpolation(): void
    {
        $patterns = $this->patterns();

        $this->assertContains('profile:{id}', $patterns);
        $this->assertContains('settings/{id}/v2', $patterns);
    }

    #[Test]
    public function it_attributes_the_write_to_the_named_disk(): void
    {
        foreach ($this->scan() as $finding) {
            if ($finding->pattern === 'avatars/{id}.jpg') {
                $this->assertSame('s3', $finding->store, 'Storage::disk("s3") names the store');
                $this->assertSame(LocationKind::ObjectStorage, $finding->kind);

                return;
            }
        }

        $this->fail('avatars path not found');
    }

    #[Test]
    public function a_key_that_names_something_other_than_a_person_is_ignored(): void
    {
        // The precision problem in one line: report:{reportId} looks identical
        // in shape to user:{userId} and must not be reported.
        $this->assertNotContains('report:{id}', $this->patterns());
    }

    #[Test]
    public function a_wholly_literal_key_is_ignored(): void
    {
        $this->assertNotContains('app:config', $this->patterns());
    }

    #[Test]
    public function reads_are_not_reported_as_writes(): void
    {
        // Cache::get() appears in the fixture with the same key as Cache::put().
        // Both collapse to one pattern, so assert we did not add a second.
        $matching = array_filter(
            $this->scan(),
            static fn (FlowFinding $f): bool => $f->pattern === 'user:{id}',
        );

        $this->assertCount(1, $matching);
    }

    #[Test]
    public function every_finding_carries_its_source_and_a_caveat(): void
    {
        foreach ($this->scan() as $finding) {
            $this->assertNotEmpty($finding->evidence);
            $this->assertStringContainsString('.php', $finding->evidence[0]);
            $this->assertContains(
                'inferred by static analysis, verify before relying on it',
                $finding->evidence,
            );
        }
    }

    #[Test]
    public function nothing_reaches_full_confidence(): void
    {
        foreach ($this->scan() as $finding) {
            $this->assertLessThan(1.0, $finding->confidence, $finding->pattern);
        }
    }

    #[Test]
    public function a_different_subject_changes_what_matches(): void
    {
        $findings = (new StaticFlowScanner)->scan(
            [__DIR__.'/../fixtures/Flow'],
            new Subject('report', 'reports.id'),
        );

        $patterns = array_map(static fn (FlowFinding $f): string => $f->pattern, $findings);

        $this->assertContains('report:{id}', $patterns);
        $this->assertNotContains('avatars/{id}.jpg', $patterns);
    }
}
