<?php

declare(strict_types=1);

namespace PrivacyCI\Verification\Probes;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use PrivacyCI\Manifest\LocationKind;
use PrivacyCI\Verification\Address;
use PrivacyCI\Verification\Probe;

/**
 * Checks the application's cache rather than a Redis connection.
 *
 * A key written with Cache::put() belongs to whatever driver the application
 * configured. Reaching for Redis directly would fail on a file or array driver,
 * and reporting that failure as UNCHECKED would be a gap nobody needed.
 */
final class CacheProbe implements Probe
{
    public function __construct(private readonly CacheFactory $cache)
    {
    }

    public function handles(Address $address): bool
    {
        return $address->kind === LocationKind::RedisKey && $address->store === 'cache';
    }

    public function exists(Address $address): bool
    {
        $key = $address->locator['key'] ?? null;

        if ($key === null) {
            throw new \RuntimeException('no key in locator');
        }

        return $this->cache->store()->has($key);
    }
}
