<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'orders';

    protected $casts = ['shipping_address' => 'encrypted'];

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }
}
