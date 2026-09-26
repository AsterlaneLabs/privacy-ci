<?php

namespace App\Search;

/** No engine named, no write call: $params['id'] here is just an array. */
class Unrelated
{
    public function payload($user): array
    {
        $params = [];
        $params['index'] = 'whatever';
        $params['id'] = $user->id;

        return $params;
    }
}
