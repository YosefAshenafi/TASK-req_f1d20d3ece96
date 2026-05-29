<?php
declare(strict_types=1);
namespace app\controller;

use think\Request;
use think\facade\Log;
use think\facade\Db;
use app\model\Activity;
use app\model\ActivityVersion;
use app\validate\ActivityValidate;
use app\service\ActivityService;
use app\service\BehaviorTracker;
use app\exception\ForbiddenException;
use app\exception\NotFoundException;

class Activity
{
    private ActivityService $service;

    public function __construct()
    {
        $this->service = new ActivityService();
    }

    public function index(Request $request)
    {
        $page    = max(1, (int)$request->get('page', 1));
        $perPage = min(50, max(1, (int)$request->get('per_page', 20)));
        $role    = $request->user_role;

        $query = Activity::with(['author', 'tags'])->scopeVisible(null, $role);

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $data = $query->paginate(['list_rows' => $perPage, 'page' => $page]);
        return json(['code' => 200, 'msg' => 'ok', 'data' => $data->toArray()]);
    }

    public function show(Request $request, int $id)
    {
        $role     = $request->user_role;
        $activity = Activity::with(['author', 'tags', 'signups'])->find($id);

        if (!$activity) throw new NotFoundException('Activity not found');

        // Regular users can only see published activities
        if ($role === 'regular' && $activity->status !== 'published') {
            throw new NotFoundException('Activity not found');
        }

        // Increment view count (fire-and-forget)
        try { Activity::where('id', $id)->inc('view_count'); } catch (\Throwable $e) {}

        return json(['code' => 200, 'msg' => 'ok', 'data' => $activity->toArray()]);
    }

    public function create(Request $request)
    {
        $data = $request->post();
        (new ActivityValidate())->scene('create')->failException(true)->check($data);

        $activity = $this->service->create($data, (int)$request->user_id);
        Log::info('activity_created', ['id' => $activity->id, 'by' => $request->user_id]);

        return json(['code' => 201, 'msg' => 'Activity created', 'data' => ['id' => $activity->id, 'status' => $activity->status]], 201);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->put();
        if (empty($data)) {
            return json(['code' => 422, 'msg' => 'No fields to update', 'errors' => []], 422);
        }

        (new ActivityValidate())->scene('update')->failException(true)->check($data);

        $activity = $this->service->update($id, $data, (int)$request->user_id, $request->user_role);
        Log::info('activity_updated', ['id' => $id, 'by' => $request->user_id]);

        return json(['code' => 200, 'msg' => 'Activity updated', 'data' => ['id' => $activity->id, 'current_version_id' => $activity->current_version_id]]);
    }

    public function transition(Request $request, int $id)
    {
        $data   = $request->put() ?: $request->post();
        $target = $data['status'] ?? '';

        if (empty($target)) {
            return json(['code' => 422, 'msg' => 'status is required', 'errors' => ['status' => 'required']], 422);
        }

        $valid = ['draft', 'published', 'in_progress', 'completed', 'archived'];
        if (!in_array($target, $valid, true)) {
            return json(['code' => 422, 'msg' => 'Invalid status value', 'errors' => ['status' => 'invalid']], 422);
        }

        try {
            $activity = $this->service->transition($id, $target, (int)$request->user_id, $request->user_role);
        } catch (\app\exception\ActivityStateException $e) {
            Log::warning('activity_bad_transition', ['id' => $id, 'target' => $target, 'error' => $e->getMessage()]);
            return json(['code' => 422, 'msg' => $e->getMessage(), 'errors' => []], 422);
        }

        return json(['code' => 200, 'msg' => 'Status updated', 'data' => [
            'id'             => $activity->id,
            'status'         => $activity->status,
            'published_at'   => $activity->published_at,
            'in_progress_at' => $activity->in_progress_at,
            'completed_at'   => $activity->completed_at,
            'archived_at'    => $activity->archived_at,
        ]]);
    }

    public function versions(Request $request, int $id)
    {
        $activity = Activity::find($id);
        if (!$activity) throw new NotFoundException('Activity not found');

        if ($request->user_role === 'regular' && $activity->status !== 'published') {
            throw new NotFoundException('Activity not found');
        }

        $versions = ActivityVersion::where('activity_id', $id)->order('version_number', 'desc')->select();
        return json(['code' => 200, 'msg' => 'ok', 'data' => $versions->toArray()]);
    }

    public function signup(Request $request, int $id)
    {
        $signup = $this->service->signup($id, (int)$request->user_id);
        return json(['code' => 200, 'msg' => 'Signed up successfully', 'data' => ['signup_id' => $signup->id]], 200);
    }

    public function cancelSignup(Request $request, int $id, int $uid)
    {
        $this->service->cancelSignup($id, $uid, (int)$request->user_id, $request->user_role);
        return json(['code' => 200, 'msg' => 'Signup canceled', 'data' => []]);
    }

    public function save(Request $request, int $id)
    {
        $userId = (int)$request->user_id;

        $activity = Activity::find($id);
        if (!$activity || $activity->status !== 'published') {
            throw new NotFoundException('Activity not found');
        }

        $existing = Db::table('activity_saves')
            ->where('user_id', $userId)
            ->where('activity_id', $id)
            ->find();

        if (!$existing) {
            Db::table('activity_saves')->insert([
                'user_id'     => $userId,
                'activity_id' => $id,
                'saved_at'    => date('Y-m-d H:i:s'),
            ]);
            try {
                (new BehaviorTracker())->record($userId, 'activity', $id, 'save');
            } catch (\Throwable $e) {}
        }

        return json(['code' => 200, 'msg' => 'Activity saved', 'data' => []]);
    }

    public function unsave(Request $request, int $id)
    {
        $userId = (int)$request->user_id;

        Db::table('activity_saves')
            ->where('user_id', $userId)
            ->where('activity_id', $id)
            ->delete();

        return json(['code' => 200, 'msg' => 'Activity unsaved', 'data' => []]);
    }
}
