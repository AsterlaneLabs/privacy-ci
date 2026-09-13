<?php

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => ['driver' => 'mysql', 'host' => '127.0.0.1'],
        // A comment mentioning snowflake must NOT produce a phantom integration.
    ],
    'redis' => [
        'client' => 'phpredis',
        'default' => ['host' => '127.0.0.1'],
    ],
];
