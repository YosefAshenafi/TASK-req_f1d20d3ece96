<?php
// PHPUnit bootstrap — sets up the Guzzle client for true no-mock HTTP tests
define('BASE_URL', getenv('BASE_URL') ?: 'http://nginx:80');
