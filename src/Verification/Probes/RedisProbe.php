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

    public function handles(Address $address): bool
    {
        // A cache key belongs to CacheProbe, which speaks whatever driver the
        // application configured rather than assuming Redis.
        return $address->kind === LocationKind::RedisKey && $address->store !== 'cache';
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
