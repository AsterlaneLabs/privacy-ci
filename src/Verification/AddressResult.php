<?php

declare(strict_types=1);

namespace PrivacyCI\Verification;

final readonly class AddressResult
{
    public function __construct(
        public Address $address,
        public Outcome $outcome,
        public ?string $detail = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'location_id' => $this->address->locationId,
            'describe' => $this->address->describe,
            'store' => $this->address->store,
            'outcome' => $this->outcome->value,
            'detail' => $this->detail,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
