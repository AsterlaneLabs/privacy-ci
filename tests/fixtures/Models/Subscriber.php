<?php

namespace App\Models;

/**
 * Reached only through an intermediate base class, and holds personal data with
 * no foreign key to users at all, the case the plan calls the hard one.
 */
class Subscriber extends BaseModel
{
    protected $fillable = ['email', 'source'];

    protected $casts = ['email' => 'encrypted'];
}
