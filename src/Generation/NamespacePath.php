<?php

declare(strict_types=1);

namespace PrivacyCI\Generation;

/**
 * Works out where a class in a given namespace belongs on disk.
 *
 * Assuming `App\` under `app/` is wrong for any application that organises code
 * by domain rather than by Laravel's default layout, a generated file would
 * land in app/Vendor/Package/Domain/… and autoload from nowhere. The
 * application already declares the answer in composer.json's PSR-4 map, so read
 * it rather than guessing.
 */
final class NamespacePath
{
    /** @var array<string, string> namespace prefix => directory */
    private array $psr4;

    /** @param array<string, string|list<string>> $psr4 */
    public function __construct(array $psr4 = [])
    {
        $normalised = [];

        foreach ($psr4 as $prefix => $paths) {
            $path = is_array($paths) ? ($paths[0] ?? null) : $paths;

            if (is_string($path)) {
                $normalised[trim($prefix, '\\')] = rtrim($path, '/');
            }
        }

        // Longest prefix first, so Vendor\Package\ beats Vendor\.
        uksort($normalised, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $this->psr4 = $normalised;
    }

    public static function fromComposer(string $composerJson): self
    {
        if (! is_file($composerJson)) {
            return new self;
        }

        $raw = file_get_contents($composerJson);

        if ($raw === false) {
            return new self;
        }

        try {
            /** @var array{autoload?: array{'psr-4'?: array<string, string|list<string>>}} $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self;
        }

        return new self($data['autoload']['psr-4'] ?? []);
    }

    /**
     * @param  string  $fallbackDirectory  Used when no PSR-4 prefix matches.
     */
    public function fileFor(string $class, string $fallbackDirectory): string
    {
        $class = trim($class, '\\');

        foreach ($this->psr4 as $prefix => $directory) {
            if ($prefix !== '' && ! str_starts_with($class.'\\', $prefix.'\\')) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $relative = str_replace('\\', '/', trim($relative, '\\'));

            return rtrim($directory, '/').'/'.$relative.'.php';
        }

        $short = str_contains($class, '\\') ? substr($class, strrpos($class, '\\') + 1) : $class;

        return rtrim($fallbackDirectory, '/').'/'.$short.'.php';
    }
}
