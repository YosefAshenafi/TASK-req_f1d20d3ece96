<?php
declare(strict_types=1);
namespace app\controller;

use think\Request;
use think\facade\Log;
use app\model\Violation as ViolationModel;
use app\model\ViolationRule;
use app\model\UserPointCache;
use app\model\GroupPointCache;
use app\service\ViolationService;
use app\exception\ForbiddenException;
use app\exception\NotFoundException;

class Violation
{
    private ViolationService $service;
    public function __construct() { $this->service = new ViolationService(); }

    public function listRules(Request $request)
    {
        if (!in_array($request->user_role, ['admin', 'team_lead'], true)) {
            throw new ForbiddenException('Insufficient permissions');
        }
        return json(['code' => 200, 'msg' => 'ok', 'data' => ViolationRule::where('is_active', 1)->select()->toArray()]);
    }

    public function createRule(Request $request)
    {
        if ($request->user_role !== 'admin') throw new ForbiddenException('Administrator access required');
        $data = $request->post();
        if (empty($data['name'])) return json(['code' => 422, 'msg' => 'name required', 'errors' => []], 422);
        if (!isset($data['point_value']) || !is_numeric($data['point_value'])) return json(['code' => 422, 'msg' => 'point_value required', 'errors' => []], 422);

        $rule = ViolationRule::create([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'point_value' => (int)$data['point_value'],
            'created_by'  => $request->user_id,
        ]);
        return json(['code' => 201, 'msg' => 'Rule created', 'data' => $rule->toArray()], 201);
    }

    public function updateRule(Request $request, int $id)
    {
        if ($request->user_role !== 'admin') throw new ForbiddenException('Administrator access required');
        $rule = ViolationRule::find($id);
        if (!$rule) throw new NotFoundException('Rule not found');
        ViolationRule::where('id', $id)->update($request->put());
        return json(['code' => 200, 'msg' => 'Rule updated', 'data' => ViolationRule::find($id)->toArray()]);
    }

    public function deleteRule(Request $request, int $id)
    {
        if ($request->user_role !== 'admin') throw new ForbiddenException('Administrator access required');
        $rule = ViolationRule::find($id);
        if (!$rule) throw new NotFoundException('Rule not found');
        ViolationRule::where('id', $id)->update(['is_active' => 0]); // soft-deactivate
        return json(['code' => 200, 'msg' => 'Rule deactivated', 'data' => []]);
    }

    public function index(Request $request)
    {
        $query = ViolationModel::with(['rule', 'subject']);
        if ($request->user_role === 'regular') {
            $query->where('subject_user_id', $request->user_id);
        }
        return json(['code' => 200, 'msg' => 'ok', 'data' => $query->paginate(20)->toArray()]);
    }

    public function create(Request $request)
    {
        if (!in_array($request->user_role, ['admin', 'team_lead'], true)) {
            throw new ForbiddenException('Administrator or Team Lead access required');
        }
        $data = $request->post();
        if (empty($data['rule_id']) || empty($data['subject_user_id'])) {
            return json(['code' => 422, 'msg' => 'rule_id and subject_user_id required', 'errors' => []], 422);
        }

        $violation = $this->service->recordViolation((int)$data['rule_id'], (int)$data['subject_user_id'], $data['group_id'] ?? null, $data['notes'] ?? null, (int)$request->user_id);
        return json(['code' => 201, 'msg' => 'Violation recorded', 'data' => ['id' => $violation->id, 'points_applied' => $violation->points_applied]], 201);
    }

    public function show(Request $request, int $id)
    {
        $v = ViolationModel::with(['rule', 'subject', 'evidence', 'appeal'])->find($id);
        if (!$v) throw new NotFoundException('Violation not found');
        if ($request->user_role === 'regular' && $v->subject_user_id !== $request->user_id) {
            throw new ForbiddenException('Access denied');
        }
        return json(['code' => 200, 'msg' => 'ok', 'data' => $v->toArray()]);
    }

    public function attachEvidence(Request $request, int $id)
    {
        // Authorization: admin, reviewer, the violation creator, or the violation subject
        $violation = \app\model\Violation::find($id);
        if (!$violation) throw new NotFoundException('Violation not found');

        $role   = $request->user_role;
        $userId = (int)$request->user_id;
        $allowed = in_array($role, ['admin', 'reviewer'], true)
            || $violation->created_by === $userId
            || $violation->subject_user_id === $userId;

        if (!$allowed) {
            throw new ForbiddenException('You are not authorized to attach evidence to this violation');
        }

        $file = $request->file('file');
        if (!$file) return json(['code' => 422, 'msg' => 'No file uploaded', 'errors' => []], 422);

        try {
            $evidence = $this->service->attachEvidence($id, $file->getPathname(), $file->getOriginalName(), $userId);
        } catch (\app\exception\AppException $e) {
            return json(['code' => 422, 'msg' => $e->getMessage(), 'errors' => []], 422);
        }

        return json(['code' => 201, 'msg' => 'Evidence attached', 'data' => ['sha256' => $evidence->sha256_hash, 'file_path' => $evidence->file_path]], 201);
    }

    public function appeal(Request $request, int $id)
    {
        $data   = $request->post();
        $reason = $data['reason'] ?? '';
        if (empty(trim($reason))) return json(['code' => 422, 'msg' => 'reason required', 'errors' => []], 422);

        $appeal = $this->service->submitAppeal($id, $reason, (int)$request->user_id);
        return json(['code' => 201, 'msg' => 'Appeal submitted', 'data' => ['appeal_id' => $appeal->id]], 201);
    }

    public function reviewAppeal(Request $request, int $id)
    {
        if (!in_array($request->user_role, ['reviewer', 'admin'], true)) throw new ForbiddenException('Reviewer access required');

        $data     = $request->put() ?: $request->post();
        $decision = $data['decision'] ?? '';
        $notes    = $data['decision_notes'] ?? '';

        if (!in_array($decision, ['approved', 'rejected'], true)) return json(['code' => 422, 'msg' => 'decision must be approved or rejected', 'errors' => []], 422);
        if (empty(trim($notes))) return json(['code' => 422, 'msg' => 'decision_notes required', 'errors' => []], 422);

        $appeal = $this->service->reviewAppeal($id, $decision, $notes, (int)$request->user_id);
        return json(['code' => 200, 'msg' => 'Review submitted', 'data' => ['status' => $appeal->status]]);
    }

    public function reReviewAppeal(Request $request, int $id)
    {
        if (!in_array($request->user_role, ['reviewer', 'admin'], true)) throw new ForbiddenException('Reviewer access required');

        $data     = $request->put() ?: $request->post();
        $decision = $data['decision'] ?? '';
        $notes    = $data['decision_notes'] ?? '';

        if (!in_array($decision, ['approved', 'rejected'], true)) return json(['code' => 422, 'msg' => 'decision must be approved or rejected', 'errors' => []], 422);
        if (empty(trim($notes))) return json(['code' => 422, 'msg' => 'decision_notes required', 'errors' => []], 422);

        try {
            $review = $this->service->reReviewAppeal($id, $decision, $notes, (int)$request->user_id);
        } catch (\app\exception\AppException $e) {
            return json(['code' => 422, 'msg' => $e->getMessage(), 'errors' => []], 422);
        }

        return json(['code' => 200, 'msg' => 'Re-review submitted', 'data' => [
            'review_id' => $review->id,
            'decision'  => $review->decision,
        ]]);
    }

    public function userPointSummary(Request $request, int $uid)
    {
        if ($request->user_role === 'regular' && $request->user_id !== $uid) throw new ForbiddenException('Access denied');
        $cache  = UserPointCache::where('user_id', $uid)->find();
        $points = $cache ? (int)$cache->total_points : 0;
        return json(['code' => 200, 'msg' => 'ok', 'data' => ['user_id' => $uid, 'total_points' => $points]]);
    }

    public function groupPointSummary(Request $request, int $gid)
    {
        // Regular users cannot query arbitrary group IDs
        if ($request->user_role === 'regular') {
            throw new ForbiddenException('Access denied');
        }
        $cache  = GroupPointCache::where('group_id', $gid)->find();
        $points = $cache ? (int)$cache->total_points : 0;
        return json(['code' => 200, 'msg' => 'ok', 'data' => ['group_id' => $gid, 'total_points' => $points]]);
    }
}
