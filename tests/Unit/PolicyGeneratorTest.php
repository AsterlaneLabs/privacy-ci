<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Discovery\Discoverer;
use PrivacyCI\Discovery\Scanners\ModelScanner;
use PrivacyCI\Generation\PolicyGenerator;
use PrivacyCI\Manifest\Subject;

final class PolicyGeneratorTest extends TestCase
{
    private Subject $subject;

    protected function setUp(): void
    {
        $this->subject = new Subject('user', 'users.id');
    }

    private function generate(bool $onlyBlocking = false): string
    {
        $models = (new ModelScanner)->scan([__DIR__.'/../fixtures/Models']);

        $manifest = (new Discoverer)->discover(
            project: 'fixture/app',
            migrationPaths: [__DIR__.'/../fixtures/migrations'],
            subject: $this->subject,
            modelPaths: [__DIR__.'/../fixtures/Models'],
        );

        return (new PolicyGenerator)->generate(
            $manifest,
            $this->subject,
            $models,
            onlyBlocking: $onlyBlocking,
        );
    }

    #[Test]
    public function the_scaffold_is_valid_php(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'policy').'.php';
        file_put_contents($file, $this->generate());

        exec('php -l '.escapeshellarg($file).' 2>&1', $output, $status);
        unlink($file);

        $this->assertSame(0, $status, implode("\n", $output));
    }

    #[Test]
    public function every_rule_is_commented_out(): void
    {
        // The founding principle: discovery suggests, developers decide. An
        // uncommented delete() here becomes real deletion code the moment
        // somebody runs privacy:make-handler.
        foreach (explode("\n", $this->generate()) as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '$this->') && ! str_starts_with($trimmed, '$this->subject(')) {
                $this->fail("uncommented rule in scaffold: {$trimmed}");
            }
        }

        $this->assertStringContainsString('// $this->delete(', $this->generate());
    }

    #[Test]
    public function the_subject_is_the_one_line_that_is_live(): void
    {
        $this->assertStringContainsString('$this->subject(User::class);', $this->generate());
    }

    #[Test]
    public function a_linked_table_suggests_anonymise_with_its_columns(): void
    {
        $this->assertStringContainsString(
            "// \$this->anonymize(Comment::class, ['author_ip' => null, 'body' => null, 'user_id' => null]);",
            $this->generate(),
        );
    }

    #[Test]
    public function a_table_with_no_link_offers_no_rule_that_could_not_work(): void
    {
        $code = $this->generate();

        // subscribers has personal data and no key to the subject, so neither
        // delete() nor anonymize() could address the rows.
        $this->assertStringContainsString('no link to the subject', $code);
        $this->assertStringContainsString('No foreign key to the subject', $code);
        $this->assertStringNotContainsString("anonymize('subscribers'", $code);
    }

    #[Test]
    public function declared_stores_get_their_own_rule(): void
    {
        $manifest = (new Discoverer)->discover(
            project: 'fixture/app',
            migrationPaths: [__DIR__.'/../fixtures/migrations'],
            subject: $this->subject,
            sourcePaths: [__DIR__.'/../fixtures/Flow'],
        );

        $code = (new PolicyGenerator)->generate($manifest, $this->subject);

        $this->assertStringContainsString('deleteRedis', $code);
        $this->assertStringContainsString('confirm the key before enabling', $code);
    }

    #[Test]
    public function only_blocking_narrows_it_to_what_fails_ci(): void
    {
        $code = $this->generate(onlyBlocking: true);

        $this->assertStringContainsString('comments', $code);
        // Heuristic-only tables never fail CI, so they are not the urgent work.
        $this->assertStringNotContainsString('── subscribers', $code);
    }

    #[Test]
    public function box_rules_are_not_corrupted_by_byte_padding(): void
    {
        $code = $this->generate();

        $this->assertTrue(mb_check_encoding($code, 'UTF-8'), 'scaffold must be valid UTF-8');
        $this->assertStringNotContainsString("\xEF\xBF\xBD", $code, 'no replacement characters');
    }

    #[Test]
    public function regenerating_unchanged_input_produces_an_identical_file(): void
    {
        $this->assertSame($this->generate(), $this->generate());
    }
}
