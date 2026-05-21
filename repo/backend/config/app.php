<?php
return [
    'name'              => 'campus',
    'app_debug'         => (bool)env('APP_DEBUG', false),
    'app_host'          => env('APP_HOST', ''),
    'app_map'           => [],
    'domain_bind'       => [],
    'deny_app_list'     => [],
    'default_timezone'  => 'UTC',
    'lang_switch_on'    => false,
    'default_lang'      => 'en',
    'json_encode_param' => JSON_UNESCAPED_UNICODE,
];
