<?php
namespace app\middleware;
class Cors
{
    public function handle($request, \Closure $next)
    {
        $response = $next($request);
        $response->header([
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Methods' => 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type,Authorization',
        ]);
        if ($request->method() === 'OPTIONS') {
            return response('', 204)->header([
                'Access-Control-Allow-Origin'  => '*',
                'Access-Control-Allow-Methods' => 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type,Authorization',
            ]);
        }
        return $response;
    }
}
