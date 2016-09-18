<?php
/**
 * This is the config file with the configuration of redis.
 *
 * @version
 * @author: daryl
 * @date: 16/9/18
 * @since:
 */

return [
    'redis' => [
        'host'     => env('REDIS_HOST', 'localhost'),
        'password' => env('REDIS_PASSWORD', null),
        'port'     => env('REDIS_PORT', 6379),
        'database' => 0,
    ],
];