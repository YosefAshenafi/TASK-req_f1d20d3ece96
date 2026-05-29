<?php
declare(strict_types=1);
namespace app\controller;

use think\Request;
use think\facade\Log;
use app\model\User as UserModel;
use app\validate\UserValidate;
use app\exception\ForbiddenException;
use app\exception\NotFoundException;
use app\exception\ConflictException;

class User
{
    /**
     * GET /api/users — Admin only; paginated user list, never exposes password_hash.
     */
    public function index(Request $request)
    {
        if ($request->user_role !== 'admin') {
            throw new ForbiddenException('Administrator access required');
        }

        $page    = max(1, (int)$request->get('page', 1));
        $perPage = min(100, max(1, (int)$request->get('per_page', 20)));

        $paginator = UserModel::field(['id', 'username', 'role', 'failed_attempts', 'locked_until', 'created_at'])
            ->paginate(['list_rows' => $perPage, 'page' => $page]);

        return json(['code' => 200, 'msg' => 'ok', 'data' => $paginator->toArray()]);
    }

    /**
     * GET /api/users/{id} — Owner or Admin only.
     */
    public function show(Request $request, int $id)
    {
        $user = UserModel::field(['id', 'username', 'role', 'created_at'])->find($id);
        if (!$user) {
            throw new NotFoundException('User not found');
        }

        // Object-level authz: regular users can only see their own profile
        if ($request->user_role !== 'admin' && $request->user_id !== $id) {
            throw new ForbiddenException('Access denied');
        }

        return json(['code' => 200, 'msg' => 'ok', 'data' => $user->toArray()]);
    }

    /**
     * POST /api/users — Admin only.
     */
    public function create(Request $request)
    {
        if ($request->user_role !== 'admin') {
            throw new ForbiddenException('Administrator access required');
        }

        $data = $request->post();
        $v    = new UserValidate();
        $v->scene('create')->failException(true)->check($data);

        if (UserModel::where('username', $data['username'])->count()) {
            throw new ConflictException('Username already taken');
        }

        $user = UserModel::create([
            'username'      => $data['username'],
            'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]),
            'role'          => $data['role'],
        ]);

        Log::info('user_created', ['admin' => $request->user_id, 'new_user' => $user->id, 'role' => $user->role]);

        return json(['code' => 201, 'msg' => 'User created', 'data' => ['id' => $user->id, 'username' => $user->username, 'role' => $user->role]], 201);
    }

    /**
     * PUT /api/users/{id} — Owner (own role cannot be changed) or Admin.
     */
    public function update(Request $request, int $id)
    {
        $target = UserModel::find($id);
        if (!$target) {
            throw new NotFoundException('User not found');
        }

        if ($request->user_role !== 'admin' && $request->user_id !== $id) {
            throw new ForbiddenException('Access denied');
        }

        $data = $request->put();
        // Only admins can change role
        if (isset($data['role']) && $request->user_role !== 'admin') {
            throw new ForbiddenException('Only administrators may change roles');
        }

        if (isset($data['password'])) {
            if (strlen($data['password']) < 10) {
                return json(['code' => 422, 'msg' => 'Password must be at least 10 characters', 'errors' => ['password' => 'too short']], 422);
            }
            $data['password_hash'] = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
            unset($data['password']);
        }

        unset($data['username']); // username is immutable
        UserModel::where('id', $id)->update($data);

        return json(['code' => 200, 'msg' => 'User updated', 'data' => []]);
    }

    /**
     * DELETE /api/users/{id} — Admin only.
     */
    public function destroy(Request $request, int $id)
    {
        if ($request->user_role !== 'admin') {
            throw new ForbiddenException('Administrator access required');
        }

        $user = UserModel::find($id);
        if (!$user) {
            throw new NotFoundException('User not found');
        }

        if ($id === $request->user_id) {
            return json(['code' => 422, 'msg' => 'Cannot delete your own account', 'errors' => []], 422);
        }

        $user->delete();
        Log::info('user_deleted', ['admin' => $request->user_id, 'deleted_user' => $id]);

        return json(['code' => 200, 'msg' => 'User deleted', 'data' => []]);
    }
}
