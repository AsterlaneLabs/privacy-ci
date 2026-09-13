<?php

declare(strict_types=1);

namespace PrivacyCI\Baseline;

use PrivacyCI\Manifest\Location;
use PrivacyCI\Manifest\Manifest;

/**
 * The findings that existed when a team adopted the tool.
 *
 * Without this, dropping the check into a mature codebase surfaces hundreds of
 * violations on day one and somebody deletes the workflow that afternoon. Every
 * linter that achieved real adoption. PHPStan, Psalm, ESLint, Semgrep, needed
 * a baseline before anyone would keep it switched on.
 */
final class Baseline
{
    public const SCHEMA_VERSION = '1.0';

    /** @var array<string, BaselineEntry> */
    private array $entries = [];

    /** @param list<BaselineEntry> $entries */
    public function __construct(array $entries = [], public readonly ?string $generatedAt = null)
    {
        foreach ($entries as $entry) {
            $this->entries[$entry->id] = $entry;
        }
    }

    public static function empty(): self
    {
        return new self;
    }

    /** Grandfather everything currently unresolved. */
    public static function from(Manifest $manifest, ?string $generatedAt = null): self
    {
        $entries = array_map(
            static fn (Location $l): BaselineEntry => new BaselineEntry(
                $l->id,
                $l->path,
                $l->linkage->value,
            ),
            $manifest->unclassified(),
        );

        return new self(array_values($entries), $generatedAt);
    }

    public function covers(string $id): bool
    {
        return isset($this->entries[$id]);
    }

    public function entry(string $id): ?BaselineEntry
    {
        return $this->entries[$id] ?? null;
    }

    /** @return list<BaselineEntry> */
    public function entries(): array
    {
        $entries = $this->entries;
        ksort($entries);

        return array_values($entries);
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * Baselined ids that the manifest no longer reports.
     *
     * Worth surfacing: a stale entry means a column was removed or reclassified,
     * and leaving it in the file silently grandfathers a future column that
     * happens to reuse the name.
     *
     * @return list<BaselineEntry>
     */
    public function staleAgainst(Manifest $manifest): array
    {
        $live = [];

        foreach ($manifest->locations() as $location) {
            $live[$location->id] = true;
        }

        return array_values(array_filter(
            $this->entries(),
            static fn (BaselineEntry $e): bool => ! isset($live[$e->id]),
        ));
    }

    public function toJson(): string
    {
        return json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $this->generatedAt,
            'note' => 'Findings that pre-date adoption of Privacy CI. '
                .'New user-linked storage is still checked; these are not. '
                .'Shrink this file over time.',
            'entries' => array_map(
                static fn (BaselineEntry $e): array => $e->toArray(),
                $this->entries(),
            ),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    public static function fromJson(string $json): self
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return new self(
            array_map(BaselineEntry::fromArray(...), (array) ($data['entries'] ?? [])),
            isset($data['generated_at']) ? (string) $data['generated_at'] : null,
        );
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            return self::empty();
        }

        $raw = file_get_contents($path);

        return $raw === false ? self::empty() : self::fromJson($raw);
    }
}
