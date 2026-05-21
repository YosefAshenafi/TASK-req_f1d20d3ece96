<?php
declare(strict_types=1);
namespace app\middleware;

use think\Request;
use think\facade\Log;
use app\service\JwtService;
use app\exception\AuthException;

class Auth
{
    public function handle(Request $request, \Closure $next)
    {
        $header = $request->header('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            throw new AuthException('Authentication required');
        }

        $token = substr($header, 7);
        try {
            $payload = JwtService::verify($token);
        } catch (\Exception $e) {
            throw new AuthException('Invalid or expired token');
        }

        // Attach user info to request for downstream use
        $request->user_id   = (int)$payload->sub;
        $request->user_role = $payload->role;
        $request->username  = $payload->username;

        Log::info('request', [
            'method' => $request->method(),
            'path'   => $request->pathinfo(),
            'user'   => $request->user_id,
            'role'   => $request->user_role,
        ]);

        return $next($request);
    }
}
