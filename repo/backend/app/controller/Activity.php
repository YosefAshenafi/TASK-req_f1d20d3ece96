<?php
declare(strict_types=1);
namespace app\controller;

use think\Request;
use think\facade\Log;
use think\facade\Db;
use app\model\Activity as ActivityModel;
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

        // Role-based visibility: regular users only see published activities
        // (mirrors Activity::scopeVisible; applied inline because named scopes are
        // not callable as ->scopeVisible() on an already-built query in ThinkPHP).
        $query = ActivityModel::with(['author', 'tags']);
        if ($role === 'regular') {
            $query->where('status', 'published');
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $data = $query->paginate(['list_rows' => $perPage, 'page' => $page]);
        return json(['code' => 200, 'msg' => 'ok', 'data' => $data->toArray()]);
    }

    public function show(Request $request, int $id)
    {
        $role     = $request->user_role;
        $activity = ActivityModel::with(['author', 'tags', 'signups'])->find($id);

        if (!$activity) throw new NotFoundException('Activity not found');

        // Regular users can only see published activities
        if ($role === 'regular' && $activity->status !== 'published') {
            throw new NotFoundException('Activity not found');
        }

        // Increment view count (fire-and-forget)
        try { ActivityModel::where('id', $id)->inc('view_count'); } catch (\Throwable $e) {}

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
        $activity = ActivityModel::find($id);
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

        $activity = ActivityModel::find($id);
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

    /**
     * DELETE /api/activities/{id} — Admin or Operations Staff.
     *
     * Hard delete with hierarchical cascade: removes the activity together with
     * every record connected to it (signups, tasks, saves, tags, versions) in a
     * single transaction, then drops the activity from the search index. Linked
     * orders are kept but unlinked (activity_id → NULL) so financial records are
     * never silently destroyed. Irreversible.
     */
    public function destroy(Request $request, int $id)
    {
        if (!in_array($request->user_role, ['admin', 'ops_staff'], true)) {
            throw new ForbiddenException('Operations Staff or Administrator access required');
        }

        $activity = ActivityModel::find($id);
        if (!$activity) throw new NotFoundException('Activity not found');

        $counts = Db::transaction(function () use ($id) {
            $c = [
                'signups'         => Db::table('activity_signups')->where('activity_id', $id)->count(),
                'tasks'           => Db::table('activity_tasks')->where('activity_id', $id)->count(),
                'saves'           => Db::table('activity_saves')->where('activity_id', $id)->count(),
                'tags'            => Db::table('activity_tags')->where('activity_id', $id)->count(),
                'versions'        => Db::table('activity_versions')->where('activity_id', $id)->count(),
                'orders_unlinked' => Db::table('orders')->where('activity_id', $id)->count(),
            ];

            // Keep orders (and their financial records) — just detach them.
            Db::table('orders')->where('activity_id', $id)->update(['activity_id' => null]);

            // Connected children.
            Db::table('activity_signups')->where('activity_id', $id)->delete();
            Db::table('activity_tasks')->where('activity_id', $id)->delete();
            Db::table('activity_saves')->where('activity_id', $id)->delete();
            Db::table('activity_tags')->where('activity_id', $id)->delete();

            // Detach current_version_id before removing versions so the self
            // reference cannot block the delete, then remove versions + activity.
            Db::table('activities')->where('id', $id)->update(['current_version_id' => null]);
            Db::table('activity_versions')->where('activity_id', $id)->delete();
            Db::table('activities')->where('id', $id)->delete();

            return $c;
        });

        try { (new \app\service\SearchIndexService())->deleteIndex('activity', $id); } catch (\Throwable $e) {}
        $this->audit($request, 'activity', $id, $counts);
        Log::info('activity_deleted', ['id' => $id, 'by' => $request->user_id, 'cascade' => $counts]);

        return json(['code' => 200, 'msg' => 'Activity deleted', 'data' => ['id' => $id, 'cascade' => $counts]]);
    }

    /** Best-effort audit-trail entry for a destructive action. */
    private function audit(Request $request, string $entityType, int $entityId, array $payload): void
    {
        try {
            Db::table('audit_log')->insert([
                'user_id'     => (int)$request->user_id,
                'action'      => 'delete',
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'payload'     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            Log::warning('audit_write_failed', ['entity' => $entityType, 'id' => $entityId, 'error' => $e->getMessage()]);
        }
    }
}
