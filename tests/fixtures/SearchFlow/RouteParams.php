<?php

namespace App\Search;

use OpenSearch\Client;

/** An `id` with no `index` is not an index request, whatever else is in the file. */
class RouteParams
{
    private Client $client;

    public function getRouteParameters(array $model, ?string $className = null)
    {
        return [
            'id' => $model['user_id'],
            'username' => $model['username'],
        ];
    }
}
