<?php

namespace Acme\Shop\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class Member extends Model
{
    protected $table = 'members';

    protected $fillable = ['email'];
}
