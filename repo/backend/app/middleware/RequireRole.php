<?php
declare(strict_types=1);
namespace app\middleware;

use think\Request;
use app\exception\ForbiddenException;

class RequireRole
{
    public function handle(Request $request, \Closure $next, string ...$roles)
    {
        if (!in_array($request->user_role, $roles, true)) {
            throw new ForbiddenException('Insufficient permissions');
        }
        return $next($request);
    }
}
