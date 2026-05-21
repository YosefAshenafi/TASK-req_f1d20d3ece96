<?php
declare(strict_types=1);
namespace app\service;

use app\model\Activity;
use app\model\ActivityVersion;
use app\model\ActivityTag;
use app\model\ActivitySignup;
use app\model\UserTag;
use app\exception\ActivityStateException;
use app\exception\ConflictException;
use app\exception\ForbiddenException;
use app\exception\NotFoundException;
use think\facade\Db;
use think\facade\Log;

class ActivityService
{
    /** Valid state machine transitions: current_state => [allowed_target_states] */
    private const TRANSITIONS = [
        'draft'       => ['published'],
        'published'   => ['in_progress', 'archived'],
        'in_progress' => ['completed'],
        'completed'   => ['archived'],
        'archived'    => [],
    ];

    /** Roles that may trigger each transition */
    private const TRANSITION_ROLES = [
        'draft->published'        => ['admin', 'ops_staff', 'team_lead'],
        'published->in_progress'  => ['admin', 'ops_staff'],
        'published->archived'     => ['admin'],
        'in_progress->completed'  => ['admin', 'ops_staff'],
        'completed->archived'     => ['admin'],
    ];

    public function create(array $data, int $authorId): Activity
    {
        $activity = Activity::create([
            'title'            => $data['title'],
            'body'             => $data['body'],
            'author_id'        => $authorId,
            'status'           => 'draft',
            'signup_open_at'   => $data['signup_open_at']   ?? null,
            'signup_close_at'  => $data['signup_close_at']  ?? null,
            'max_headcount'    => $data['max_headcount']    ?? null,
            'required_supplies'=> $data['required_supplies'] ?? [],
        ]);

        if (!empty($data['tags'])) {
            $this->syncTags($activity->id, (array)$data['tags']);
        }

        $this->triggerIndex($activity->id);
        return $activity;
    }

    public function update(int $id, array $data, int $updaterId, string $role): Activity
    {
        $activity = Activity::find($id);
        if (!$activity) throw new NotFoundException('Activity not found');

        // Authz: only author or admin can edit
        if ($activity->author_id !== $updaterId && $role !== 'admin') {
            throw new ForbiddenException('Only the author or an administrator may edit this activity');
        }

        $wasPublished = in_array($activity->status, ['published', 'in_progress', 'completed'], true);

        if ($wasPublished) {
            // Compute diff against current snapshot
            $oldSnapshot = $activity->toArray();
            $newData     = array_merge($oldSnapshot, $data);
            $diff        = [];
            foreach ($data as $field => $newVal) {
                if (isset($oldSnapshot[$field]) && $oldSnapshot[$field] !== $newVal) {
                    $diff[$field] = ['old' => $oldSnapshot[$field], 'new' => $newVal];
                }
            }

            // Determine next version number
            $lastVersion = ActivityVersion::where('activity_id', $id)->max('version_number') ?? 0;
            $version     = ActivityVersion::create([
                'activity_id'    => $id,
                'version_number' => $lastVersion + 1,
                'snapshot'       => $newData,
                'diff'           => $diff,
                'changed_by'     => $updaterId,
                'action'         => 'edit',
            ]);

            Activity::where('id', $id)->update(array_merge($data, ['current_version_id' => $version->id]));
        } else {
            Activity::where('id', $id)->update($data);
        }

        if (isset($data['tags'])) {
            $this->syncTags($id, (array)$data['tags']);
        }

        $this->triggerIndex($id);
        return Activity::find($id);
    }

    public function transition(int $id, string $target, int $userId, string $role): Activity
    {
        $activity = Activity::find($id);
        if (!$activity) throw new NotFoundException('Activity not found');

        $current = $activity->status;
        $allowed = self::TRANSITIONS[$current] ?? [];

        if (!in_array($target, $allowed, true)) {
            throw new ActivityStateException("Cannot transition from '{$current}' to '{$target}'");
        }

        $key          = "{$current}->{$target}";
        $allowedRoles = self::TRANSITION_ROLES[$key] ?? ['admin'];
        if (!in_array($role, $allowedRoles, true)) {
            throw new ForbiddenException("Role '{$role}' may not perform this transition");
        }

        Activity::where('id', $id)->update(['status' => $target]);
        Log::info('activity_transition', ['id' => $id, 'from' => $current, 'to' => $target, 'by' => $userId]);

        $this->triggerIndex($id);
        return Activity::find($id);
    }

    public function signup(int $activityId, int $userId): ActivitySignup
    {
        $activity = Activity::find($activityId);
        if (!$activity || $activity->status !== 'published') {
            throw new NotFoundException('Activity not found or not open for signup');
        }

        // Window check
        $now = time();
        if ($activity->signup_open_at && strtotime($activity->signup_open_at) > $now) {
            throw new ActivityStateException('Signup window has not opened yet');
        }
        if ($activity->signup_close_at && strtotime($activity->signup_close_at) < $now) {
            throw new ActivityStateException('Signup window has closed');
        }

        // Headcount check
        if ($activity->max_headcount !== null) {
            $count = ActivitySignup::where('activity_id', $activityId)->where('status', 'active')->count();
            if ($count >= $activity->max_headcount) {
                throw new ConflictException('Activity is at maximum capacity');
            }
        }

        // Eligibility tag check
        $requiredTags = ActivityTag::where('activity_id', $activityId)->column('tag');
        if (!empty($requiredTags)) {
            $userTags = UserTag::where('user_id', $userId)->column('tag');
            if (empty(array_intersect($requiredTags, $userTags))) {
                throw new ForbiddenException('You do not have the required eligibility tags for this activity');
            }
        }

        // Check existing signup
        $existing = ActivitySignup::where('activity_id', $activityId)->where('user_id', $userId)->find();
        if ($existing) {
            if ($existing->status === 'active') throw new ConflictException('Already signed up');
            // Reactivate canceled signup
            ActivitySignup::where('id', $existing->id)->update(['status' => 'active', 'signed_up_at' => date('Y-m-d H:i:s')]);
            return ActivitySignup::find($existing->id);
        }

        return ActivitySignup::create(['activity_id' => $activityId, 'user_id' => $userId]);
    }

    public function cancelSignup(int $activityId, int $userId, int $requesterId, string $role): void
    {
        if ($requesterId !== $userId && $role !== 'admin') {
            throw new ForbiddenException('You may only cancel your own signup');
        }

        $signup = ActivitySignup::where('activity_id', $activityId)->where('user_id', $userId)->find();
        if (!$signup) throw new NotFoundException('Signup not found');

        ActivitySignup::where('id', $signup->id)->update(['status' => 'canceled']);
    }

    private function syncTags(int $activityId, array $tags): void
    {
        ActivityTag::where('activity_id', $activityId)->delete();
        foreach (array_unique($tags) as $tag) {
            ActivityTag::create(['activity_id' => $activityId, 'tag' => trim($tag)]);
        }
    }

    private function triggerIndex(int $activityId): void
    {
        try {
            app(\app\service\SearchIndexService::class)->indexActivity($activityId);
        } catch (\Throwable $e) {
            Log::warning('index_trigger_failed', ['activity_id' => $activityId, 'error' => $e->getMessage()]);
        }
    }
}
