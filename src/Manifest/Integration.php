<?php

declare(strict_types=1);

namespace PrivacyCI\Manifest;

/**
 * A store or third-party service the application talks to.
 *
 * Detecting one without any classified locations inside it is itself a finding:
 * it means personal data may be flowing somewhere nobody has mapped.
 */
final readonly class Integration
{
    /**
     * @param  bool  $supported  False means we detected it but cannot scan it yet. Printing
     *                           these is how free discovery generates demand
     *                           signal for connectors worth building next.
     */
    public function __construct(
        public string $kind,
        public string $detectedFrom,
        public bool $supported = true,
    ) {
    }

    /** @return array{kind: string, detected_from: string, supported: bool} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'detected_from' => $this->detectedFrom,
            'supported' => $this->supported,
        ];
    }

    /** @param array{kind: string, detected_from: string, supported?: bool} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['kind'], $data['detected_from'], $data['supported'] ?? true);
    }
}
