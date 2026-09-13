<?php

declare(strict_types=1);

namespace PrivacyCI\Manifest;

/** A person whose data the application stores, and the column that identifies them. */
final readonly class Subject
{
    public function __construct(
        public string $type,
        public string $root,
    ) {
    }

    /** The table half of the root, e.g. "users" from "users.id". */
    public function rootTable(): string
    {
        return str_contains($this->root, '.')
            ? substr($this->root, 0, strpos($this->root, '.'))
            : $this->root;
    }

    /** The column half of the root, e.g. "id" from "users.id". */
    public function rootColumn(): string
    {
        return str_contains($this->root, '.')
            ? substr($this->root, strpos($this->root, '.') + 1)
            : 'id';
    }

    /** @return array{type: string, root: string} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'root' => $this->root];
    }

    /** @param array{type: string, root: string} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['type'], $data['root']);
    }
}
