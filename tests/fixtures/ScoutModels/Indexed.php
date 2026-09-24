<?php

namespace App\Search;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

abstract class Indexed extends Model
{
    use Searchable;
}
