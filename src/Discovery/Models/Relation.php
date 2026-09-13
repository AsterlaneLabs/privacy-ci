<?php

declare(strict_types=1);

namespace PrivacyCI\Discovery\Models;

/** A declared Eloquent relationship between two models. */
final readonly class Relation
{
    public function __construct(
        public string $method,
        public string $type,
        public string $relatedClass,
        public ?string $foreignKey = null,
    ) {
    }

    /**
     * Whether the foreign key sits on *this* model's table.
     *
     * belongsTo puts it here; hasMany/hasOne put it on the related table. Getting
     * this backwards would attribute the column to the wrong table entirely.
     */
    public function keyIsLocal(): bool
    {
        return in_array($this->type, ['belongsTo', 'morphTo'], true);
    }

    public function isSupported(): bool
    {
        return in_array($this->type, [
            'belongsTo', 'hasMany', 'hasOne', 'morphMany', 'morphOne',
        ], true);
    }
}
