<?php

namespace App\Search;

use Illuminate\Database\Eloquent\Model;

trait Searchable
{
}

class Note extends Model
{
    use Searchable;
}
