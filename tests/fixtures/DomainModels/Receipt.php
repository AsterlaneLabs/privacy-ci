<?php

namespace Acme\Shop\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/** Linked to the subject only by a declared relationship. */
class Receipt extends Model
{
    protected $table = 'receipts';

    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
