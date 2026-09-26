<?php

namespace App\Search;

class InlineClient
{
    public function __construct(private $client)
    {
    }

    public function remove($user): void
    {
        // Named neither OpenSearch nor Elasticsearch anywhere in this file; the
        // write call is what marks the array as a request.
        $this->client->delete(['index' => 'members', 'id' => $user->id]);
    }

    public function store($order): void
    {
        // Keyed by an order, not a person.
        $this->client->index(['index' => 'orders', 'id' => $order->id]);
    }
}
