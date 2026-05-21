<?php
declare(strict_types=1);
namespace app\middleware;

use think\Request;
use app\service\BehaviorTracker;
use think\facade\Log;

class BehaviorCapture
{
    public function handle(Request $request, \Closure $next)
    {
        $response = $next($request);

        // Only capture GET requests to /api/activities/{numeric_id} (not sub-resources)
        if ($request->method() !== 'GET') return $response;
        if ($response->getCode() !== 200)  return $response;

        $path = $request->pathinfo();
        if (!preg_match('#^api/activities/(\d+)$#', $path, $m)) return $response;

        $entityId = (int)$m[1];
        $userId   = $request->user_id ?? null;

        if ($userId) {
            try {
                (new BehaviorTracker())->record((int)$userId, 'activity', $entityId, 'view');
            } catch (\Throwable $e) {
                Log::warning('behavior_capture_failed', ['error' => $e->getMessage()]);
            }
        }

        return $response;
    }
}
