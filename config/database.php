<?php

return [
    'default'         => 'mysql',
    'time_query_rule' => [],
    'connections'     => [
        'mysql' => [
            'type'            => 'mysql',
            'hostname'        => '127.0.0.1',
            'database'        => 'tg_cert_bot',
            'username'        => 'root',
            'password'        => '',
            'hostport'        => '3306',
            'charset'         => 'utf8mb4',
            'prefix'          => '',
            'debug'           => true,
            'fields_strict'   => true,
            'resultset_type'  => 'array',
            'auto_timestamp'  => true,
            'datetime_format' => 'Y-m-d H:i:s',
        ],
    ],
];
