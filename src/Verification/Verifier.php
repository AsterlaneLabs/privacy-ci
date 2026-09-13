<?php

declare(strict_types=1);

namespace PrivacyCI\Verification;

/**
 * Checks a captured footprint against reality.
 *
 * Three rules, all of which exist to stop the report overstating itself:
 *
 *   - An address with no probe is Unchecked, never Pass.
 *   - A probe that throws is Unchecked, never Pass.
 *   - An address we could not resolve at capture time stays Unchecked forever.
 */
final class Verifier
{
    /** @param list<Probe> $probes */
    public function __construct(private readonly array $probes = [])
    {
    }

    public function verify(Footprint $footprint, ?string $verifiedAt = null): VerificationResult
    {
        $results = [];

        foreach ($footprint->addresses as $address) {
            $results[] = $this->check($address);
        }

        return new VerificationResult(
            $footprint->subjectType,
            $footprint->subjectId,
            $results,
            $verifiedAt,
        );
    }

    private function check(Address $address): AddressResult
    {
        if (! $address->isCheckable()) {
            return new AddressResult(
                $address,
                Outcome::Unchecked,
                $address->reason ?? 'not addressable from the subject id',
            );
        }

        $probe = $this->probeFor($address);

        if ($probe === null) {
            return new AddressResult(
                $address,
                Outcome::Unchecked,
                sprintf('no probe registered for %s', $address->kind->value),
            );
        }

        try {
            $exists = $probe->exists($address);
        } catch (\Throwable $e) {
            // A store we could not reach has told us nothing at all.
            return new AddressResult($address, Outcome::Unchecked, 'probe failed: '.$e->getMessage());
        }

        if ($address->expectation === Expectation::Retained) {
            return new AddressResult(
                $address,
                Outcome::Retained,
                $address->reason ?? 'retained by policy',
            );
        }

        return $exists
            ? new AddressResult($address, Outcome::Fail, 'data still present')
            : new AddressResult($address, Outcome::Pass, null);
    }

    private function probeFor(Address $address): ?Probe
    {
        foreach ($this->probes as $probe) {
            if ($probe->handles($address)) {
                return $probe;
            }
        }

        return null;
    }
}
