<?php
declare(strict_types=1);
namespace app\controller;

use think\Request;
use think\facade\Log;
use app\model\User;
use app\model\AuditLog;
use app\validate\AuthValidate;
use app\service\JwtService;
use app\exception\AuthException;

class Auth
{
    public function login(Request $request)
    {
        $data = $request->post();

        $validate = new AuthValidate();
        $validate->failException(true)->check($data);

        /** @var User|null $user */
        $user = User::where('username', $data['username'])->find();

        if (!$user) {
            // Constant-time dummy verify to prevent username enumeration timing
            password_verify($data['password'], '$2y$12$xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
            Log::warning('login_unknown_user', ['username' => $data['username']]);
            return json(['code' => 401, 'msg' => 'Invalid credentials', 'errors' => []], 401);
        }

        // Check lockout
        $lockedUntil = $user->getData('locked_until');
        if ($lockedUntil && strtotime($lockedUntil) > time()) {
            $remaining = strtotime($lockedUntil) - time();
            Log::warning('login_locked', ['user_id' => $user->id]);
            return json(['code' => 401, 'msg' => "Account locked. Try again in {$remaining} seconds.", 'errors' => []], 401);
        }

        if (!$user->verifyPassword($data['password'])) {
            $attempts = (int)$user->getData('failed_attempts') + 1;
            $updates  = ['failed_attempts' => $attempts];
            if ($attempts >= 5) {
                $updates['locked_until'] = date('Y-m-d H:i:s', time() + 900); // 15 minutes
            }
            User::where('id', $user->id)->update($updates);
            Log::warning('login_bad_password', ['user_id' => $user->id, 'attempts' => $attempts]);
            return json(['code' => 401, 'msg' => 'Invalid credentials', 'errors' => []], 401);
        }

        // Success — reset lockout counters
        User::where('id', $user->id)->update(['failed_attempts' => 0, 'locked_until' => null]);

        $token = JwtService::issue((int)$user->id, $user->role, $user->username);

        AuditLog::record((int)$user->id, 'login', 'user', (int)$user->id);

        Log::info('login_success', ['user_id' => $user->id, 'role' => $user->role]);

        return json([
            'code' => 200,
            'msg'  => 'Login successful',
            'data' => [
                'token' => $token,
                'user'  => ['id' => $user->id, 'username' => $user->username, 'role' => $user->role],
            ],
        ]);
    }

    public function logout(Request $request)
    {
        // JWT is stateless; client discards the token.
        AuditLog::record((int)$request->user_id, 'logout', 'user', (int)$request->user_id);
        Log::info('logout', ['user_id' => $request->user_id]);
        return json(['code' => 200, 'msg' => 'Logged out', 'data' => []]);
    }
}
