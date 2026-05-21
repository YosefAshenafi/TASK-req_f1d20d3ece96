<?php
// ThinkPHP 6.x entry point
define('APP_PATH', __DIR__ . '/../');

require APP_PATH . 'vendor/autoload.php';

$http = (new think\App())->http;
$response = $http->run();
$response->send();
$http->end($response);
