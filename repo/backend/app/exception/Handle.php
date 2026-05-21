<?php
declare(strict_types=1);

namespace app\exception;

use think\exception\Handle as ThinkHandle;
use think\exception\ValidateException;
use think\Response;
use think\Request;
use think\facade\Log;
use Throwable;

class Handle extends ThinkHandle
{
    protected $ignoreReport = [
        \app\exception\AppException::class,
    ];

    public function render($request, Throwable $e): Response
    {
        // Validation error
        if ($e instanceof ValidateException) {
            Log::warning('validation_failed', ['errors' => $e->getError(), 'path' => $request->pathinfo()]);
            return json(['code' => 422, 'msg' => 'Validation failed', 'errors' => $e->getError()], 422);
        }

        // Auth errors
        if ($e instanceof \app\exception\AuthException) {
            Log::warning('auth_error', ['msg' => $e->getMessage(), 'path' => $request->pathinfo()]);
            return json(['code' => 401, 'msg' => $e->getMessage(), 'errors' => []], 401);
        }

        // Forbidden
        if ($e instanceof \app\exception\ForbiddenException) {
            Log::warning('forbidden', ['msg' => $e->getMessage(), 'path' => $request->pathinfo()]);
            return json(['code' => 403, 'msg' => $e->getMessage(), 'errors' => []], 403);
        }

        // Not found
        if ($e instanceof \app\exception\NotFoundException) {
            return json(['code' => 404, 'msg' => $e->getMessage(), 'errors' => []], 404);
        }

        // Conflict
        if ($e instanceof \app\exception\ConflictException) {
            return json(['code' => 409, 'msg' => $e->getMessage(), 'errors' => []], 409);
        }

        // Domain exceptions (state machine violations, etc.)
        if ($e instanceof \app\exception\AppException) {
            return json(['code' => 422, 'msg' => $e->getMessage(), 'errors' => []], 422);
        }

        // Unexpected errors — log full details, never expose them
        Log::error('unhandled_exception', [
            'class'   => get_class($e),
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'path'    => $request->pathinfo(),
        ]);

        return json(['code' => 500, 'msg' => 'Internal server error', 'errors' => []], 500);
    }
}
