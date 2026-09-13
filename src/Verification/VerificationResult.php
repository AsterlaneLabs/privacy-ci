<?php

declare(strict_types=1);

namespace PrivacyCI\Verification;

/**
 * What verification established, and, just as importantly, what it did not.
 */
final readonly class VerificationResult
{
    /** @param list<AddressResult> $results */
    public function __construct(
        public string $subjectType,
        public string $subjectId,
        public array $results = [],
        public ?string $verifiedAt = null,
    ) {
    }

    /** @return list<AddressResult> */
    public function of(Outcome $outcome): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (AddressResult $r): bool => $r->outcome === $outcome,
        ));
    }

    public function passed(): bool
    {
        return $this->of(Outcome::Fail) === [];
    }

    /**
     * Whether every address was actually looked at.
     *
     * A report can pass and still be incomplete; saying so is the difference
     * between evidence and a comforting noise.
     */
    public function complete(): bool
    {
        return $this->of(Outcome::Unchecked) === [];
    }

    public function count(Outcome $outcome): int
    {
        return count($this->of($outcome));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'verified_at' => $this->verifiedAt,
            'passed' => $this->passed(),
            'complete' => $this->complete(),
            'results' => array_map(static fn (AddressResult $r): array => $r->toArray(), $this->results),
        ];
    }

    /** Hash-chainable evidence: stable, and independent of when it ran. */
    public function fingerprint(): string
    {
        $body = $this->toArray();
        unset($body['verified_at']);

        return 'sha256:'.hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
    }
}
