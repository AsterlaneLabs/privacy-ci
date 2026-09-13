<?php

declare(strict_types=1);

namespace PrivacyCI\Verification\Probes;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use PrivacyCI\Manifest\LocationKind;
use PrivacyCI\Verification\Address;
use PrivacyCI\Verification\Probe;

final class StorageProbe implements Probe
{
    public function __construct(private readonly FilesystemFactory $filesystem)
    {
    }

    public function handles(LocationKind $kind): bool
    {
        return $kind === LocationKind::ObjectStorage;
    }

    public function exists(Address $address): bool
    {
        $path = $address->locator['key'] ?? null;

        if ($path === null) {
            throw new \RuntimeException('no path in locator');
        }

        $disk = in_array($address->store, ['storage', 'local'], true) ? null : $address->store;

        return $this->filesystem->disk($disk)->exists($path);
    }
}
