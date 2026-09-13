<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Console\Concerns\WritesReports;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Reports and generated code go to the stream beneath SymfonyStyle, because
 * SymfonyStyle normalises whitespace: it collapses runs of spaces and drops
 * blank lines, which flattens a padded report and breaks generated PHP.
 */
final class WritesReportsTest extends TestCase
{
    /** @return array{0: object, 1: resource} */
    private function subject(bool $decorated = false): array
    {
        $stream = fopen('php://memory', 'w+');

        $command = new class extends Command
        {
            use WritesReports;

            public function emitReport(string $t): void
            {
                $this->writeReport($t);
            }

            public function emitVerbatim(string $t): void
            {
                $this->writeVerbatim($t);
            }

            public function decorated(): bool
            {
                return $this->reportsAreDecorated();
            }
        };

        $command->setOutput(new OutputStyle(new ArrayInput([]), new StreamOutput($stream, decorated: $decorated)));

        return [$command, $stream];
    }

    private function read($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    #[Test]
    public function column_alignment_survives(): void
    {
        [$command, $stream] = $this->subject();
        $command->emitReport("  users.email        0.99\n  comments.user_id   1.00");

        $this->assertStringContainsString('  users.email        0.99', $this->read($stream));
    }

    #[Test]
    public function blank_lines_survive(): void
    {
        [$command, $stream] = $this->subject();
        $command->emitReport("HEADING\n\n  a row");

        $this->assertStringContainsString("HEADING\n\n  a row", $this->read($stream));
    }

    #[Test]
    public function verbatim_adds_nothing_before_the_text(): void
    {
        [$command, $stream] = $this->subject();
        $command->emitVerbatim("<?php\n\ndeclare(strict_types=1);");

        // Anything before the opening tag is output, and then the declare is no
        // longer the first statement.
        $this->assertStringStartsWith('<?php', $this->read($stream));
    }

    #[Test]
    public function a_report_is_padded_but_verbatim_is_not(): void
    {
        [$report, $a] = $this->subject();
        $report->emitReport('x');

        [$verbatim, $b] = $this->subject();
        $verbatim->emitVerbatim('x');

        $this->assertSame("\nx\n\n", $this->read($a));
        $this->assertSame("x\n", $this->read($b));
    }

    #[Test]
    public function decoration_follows_the_stream_being_written_to(): void
    {
        [$plain] = $this->subject(decorated: false);
        [$colour] = $this->subject(decorated: true);

        $this->assertFalse($plain->decorated());
        $this->assertTrue($colour->decorated());
    }

    #[Test]
    public function markup_in_the_text_is_not_interpreted(): void
    {
        [$command, $stream] = $this->subject();
        $command->emitVerbatim("\$this->anonymize('users', ['email' => '<redacted>']);");

        // Symfony would read <redacted> as a style tag and swallow it.
        $this->assertStringContainsString('<redacted>', $this->read($stream));
    }
}
