<?php

namespace App\Search;

/**
 * The shape that matters: the request is assembled here and sent from a
 * different method, in a different file. Gets the user data into OpenSearch.
 */
trait UsersIntoOpenSearch
{
    protected function getOpenSearchUserData($user, $index)
    {
        $params = [];
        $params['index'] = $index;
        $params['id'] = $user->user_id;

        $params['body']['type'] = 'user';
        $params['body']['username'] = $user->username;
        $params['body']['status'] = $user->status;

        return $params;
    }
}
