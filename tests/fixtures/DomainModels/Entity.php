<?php

namespace Acme\Shop\Domain\Models;

/**
 * A base class that lives outside the scanned paths in a real application and
 * is not named "*Model", so ancestry alone cannot recognise it.
 */
abstract class Entity extends \Illuminate\Database\Eloquent\Model
{
}
