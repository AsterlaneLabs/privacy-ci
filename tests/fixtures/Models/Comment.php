<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Comment extends Model
{
    use Searchable;

    protected $fillable = ['body'];

    public function searchableAs(): string
    {
        return 'comments_index';
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
