<?php

declare(strict_types=1);

namespace PrivacyCI\Verification\Probes;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use PrivacyCI\Manifest\LocationKind;
use PrivacyCI\Verification\Address;
use PrivacyCI\Verification\Probe;

final class RedisProbe implements Probe
{
    public function __construct(private readonly RedisFactory $redis)
    {
    }

    public function handles(LocationKind $kind): bool
    {
        return $kind === LocationKind::RedisKey;
    }

    public function exists(Address $address): bool
    {
        $key = $address->locator['key'] ?? null;

        if ($key === null) {
            throw new \RuntimeException('no key in locator');
        }

        $connection = in_array($address->store, ['cache', 'redis', 'session'], true)
            ? null
            : $address->store;

        return (bool) $this->redis->connection($connection)->exists($key);
    }
}
