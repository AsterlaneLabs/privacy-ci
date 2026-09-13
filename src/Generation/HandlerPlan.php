<?php

declare(strict_types=1);

namespace PrivacyCI\Generation;

use PrivacyCI\Manifest\Classification;

/** Everything a generated handler needs, already ordered. */
final readonly class HandlerPlan
{
    /**
     * @param  list<HandlerStep>  $steps
     * @param  list<string>       $unresolved  Locations with no policy, emitted as TODOs.
     */
    public function __construct(
        public array $steps = [],
        public array $unresolved = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->steps === [];
    }
}
