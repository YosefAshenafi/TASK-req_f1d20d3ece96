<?php
return [
    'default'    => 'mysql',
    'connections' => [
        'mysql' => [
            'type'      => 'mysql',
            'hostname'  => env('DB_HOST', 'db'),
            'database'  => env('DB_NAME', 'campus'),
            'username'  => env('DB_USER', 'campus'),
            'password'  => env('DB_PASSWORD', 'campus'),
            'hostport'  => env('DB_PORT', '3306'),
            'charset'   => 'utf8mb4',
            'prefix'    => '',
            'debug'     => false,
            'fields_strict' => false,
            'params'    => [PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false],
        ],
    ],
];
