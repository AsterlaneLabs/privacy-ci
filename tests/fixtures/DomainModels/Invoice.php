<?php

namespace Acme\Shop\Domain\Models;

class Invoice extends \Acme\Support\Database\Entity
{
    protected $table = 'invoices';

    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
