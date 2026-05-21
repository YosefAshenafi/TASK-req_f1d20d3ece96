<?php
return [
    'default'  => 'file',
    'channels' => [
        'file' => [
            'type'           => 'File',
            'path'           => '',
            'single'         => false,
            'file_size'      => 2097152,
            'apart_level'    => [],
            'format'         => '[%s][%s] %s',
            'realtime_write' => true,
        ],
    ],
];
