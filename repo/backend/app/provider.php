<?php
// Container bindings. Registers the application's custom exception handler so
// API errors return clean JSON ({code,msg,errors}) instead of ThinkPHP's HTML
// error page (which crashes on htmlentities(null) under PHP 8.2, surfacing as a
// generic 500). Without this binding the well-formed app\exception\Handle is
// never used and unhandled exceptions render the broken default template.
return [
    'think\exception\Handle' => \app\exception\Handle::class,
];
