<?php

declare(strict_types=1);

namespace PrivacyCI\Reporting;

use PrivacyCI\Verification\AddressResult;
use PrivacyCI\Verification\Outcome;
use PrivacyCI\Verification\VerificationResult;

/** Renders a verification result, including what it could not establish. */
final class VerificationReport
{
    public function __construct(private readonly bool $ansi = true)
    {
    }

    public function render(VerificationResult $result): string
    {
        $width = 0;

        foreach ($result->results as $r) {
            $width = max($width, strlen($r->address->describe));
        }

        $lines = [
            $this->dim(sprintf(
                'VERIFICATION: %s %s',
                $result->subjectType,
                $result->subjectId,
            )),
            '',
        ];

        foreach ($result->results as $r) {
            $lines[] = sprintf(
                '  %s  %s  %s',
                str_pad($r->address->describe, $width),
                $this->label($r),
                $this->dim((string) $r->detail),
            );
        }

        $lines[] = '';
        $lines[] = $this->summary($result);

        if (! $result->complete()) {
            $lines[] = '';
            $lines[] = $this->amber(sprintf(
                '  %d address(es) were not checked. This report does not establish',
                $result->count(Outcome::Unchecked),
            ));
            $lines[] = $this->amber('  that the subject was erased from them.');
        }

        return implode("\n", $lines);
    }

    private function label(AddressResult $r): string
    {
        return match ($r->outcome) {
            Outcome::Pass => $this->green(str_pad('PASS', 9)),
            Outcome::Fail => $this->red(str_pad('FAIL', 9)),
            Outcome::Retained => $this->dim(str_pad('RETAINED', 9)),
            Outcome::Unchecked => $this->amber(str_pad('UNCHECKED', 9)),
        };
    }

    private function summary(VerificationResult $result): string
    {
        $parts = [];

        foreach ([Outcome::Pass, Outcome::Fail, Outcome::Retained, Outcome::Unchecked] as $outcome) {
            $count = $result->count($outcome);

            if ($count > 0) {
                $parts[] = sprintf('%d %s', $count, $outcome->value);
            }
        }

        $verdict = match (true) {
            ! $result->passed() => $this->red('VERIFICATION FAILED'),
            ! $result->complete() => $this->amber('VERIFIED WITH GAPS'),
            default => $this->green('VERIFIED'),
        };

        return $verdict.' · '.implode(' · ', $parts).' · '.$this->dim($result->fingerprint());
    }

    private function dim(string $t): string
    {
        return $this->ansi && $t !== '' ? "\033[2m{$t}\033[0m" : $t;
    }

    private function red(string $t): string
    {
        return $this->ansi ? "\033[31m{$t}\033[0m" : $t;
    }

    private function green(string $t): string
    {
        return $this->ansi ? "\033[32m{$t}\033[0m" : $t;
    }

    private function amber(string $t): string
    {
        return $this->ansi ? "\033[33m{$t}\033[0m" : $t;
    }
}
