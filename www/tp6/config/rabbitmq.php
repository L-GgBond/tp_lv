<?php
// +----------------------------------------------------------------------
// | rabbitmq
// +----------------------------------------------------------------------

return [
    'host'     => env('RABBITMQ.HOST', 'host.docker.internal'),
    'port'     => env('RABBITMQ.PORT', 5672),
    'user'     => env('RABBITMQ.USER', 'guest'),
    'password' => env('RABBITMQ.PASSWORD', 'guest'),
    'vhost'    => env('RABBITMQ.VHOST', '/'),
];
