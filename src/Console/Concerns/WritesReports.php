<?php

declare(strict_types=1);

namespace PrivacyCI\Console\Concerns;

use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes a pre-formatted report block to the console verbatim.
 *
 * Laravel's $this->output is a SymfonyStyle, which trims and normalises
 * whitespace by design, silently destroying column alignment in anything built
 * with str_pad. Writing to the stream beneath it, with OUTPUT_RAW so the
 * formatter does not touch tags either, delivers the report exactly as rendered.
 * ANSI codes survive because they are bytes rather than markup.
 */
trait WritesReports
{
    /**
     * Whether the stream we write to will render colour.
     *
     * Read from the same object writeReport() writes to. Asking the SymfonyStyle
     * wrapper instead can disagree with the stream beneath it, which renders a
     * report in plain text while claiming it was decorated.
     */
    protected function reportsAreDecorated(): bool
    {
        return $this->reportStream()->isDecorated();
    }

    private function reportStream(): OutputInterface
    {
        return $this->output instanceof OutputStyle
            ? $this->output->getOutput()
            : $this->output;
    }

    protected function writeReport(string $text): void
    {
        // Not $this->output: that is a SymfonyStyle, which trims and normalises
        // whitespace as a matter of policy. getOutput() is the raw stream beneath it.
        $out = $this->reportStream();

        $out->writeln('', OutputInterface::OUTPUT_RAW);
        $out->writeln($text, OutputInterface::OUTPUT_RAW);
        $out->writeln('', OutputInterface::OUTPUT_RAW);
    }
}
