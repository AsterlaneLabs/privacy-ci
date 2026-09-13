<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Deliberately has no migration and no database-level foreign key.
 * Only the declared relationship reveals that audit_entries.actor_id is a person.
 */
class AuditEntry extends Model
{
    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
