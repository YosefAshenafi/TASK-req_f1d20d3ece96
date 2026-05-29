<?php
// PHPUnit bootstrap — sets up the Guzzle client for true no-mock HTTP tests
define('BASE_URL', getenv('BASE_URL') ?: 'http://nginx:80');

// Bootstrap the ThinkPHP application container so tests may use the Db facade
// for direct database setup/assertions. The database connection reads the same
// env vars (DB_HOST/DB_NAME/DB_USER/DB_PASSWORD) injected into the backend
// container. This only initializes the container/config — it does not handle an
// HTTP request, so it has no effect on the no-mock HTTP tests (which go through
// Guzzle → nginx → a separate PHP-FPM process).
require __DIR__ . '/../vendor/autoload.php';
$app = new think\App();
$app->initialize();
