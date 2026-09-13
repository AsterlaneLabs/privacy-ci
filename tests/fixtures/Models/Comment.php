<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    protected $fillable = ['body'];

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
