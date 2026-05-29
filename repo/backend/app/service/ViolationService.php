<?php
declare(strict_types=1);
namespace app\service;

use app\model\Violation;
use app\model\ViolationRule;
use app\model\ViolationEvidence;
use app\model\ViolationAppeal;
use app\model\ViolationAppealReview;
use app\model\UserPointCache;
use app\model\GroupPointCache;
use app\model\Notification;
use app\model\User;
use app\exception\NotFoundException;
use app\exception\ConflictException;
use app\exception\AppException;
use think\facade\Db;
use think\facade\Log;
use think\file\UploadedFile;

class ViolationService
{
    private const MIME_WHITELIST = ['image/jpeg', 'image/png', 'application/pdf'];
    private const ALLOWED_EXTS  = ['jpg', 'jpeg', 'png', 'pdf'];
    private const MAX_BYTES      = 10485760; // 10 MB

    public function recordViolation(int $ruleId, int $subjectUserId, ?int $groupId, ?string $notes, int $createdBy): Violation
    {
        $rule = ViolationRule::find($ruleId);
        if (!$rule || !$rule->is_active) {
            throw new NotFoundException('Violation rule not found or inactive');
        }

        return Db::transaction(function () use ($rule, $subjectUserId, $groupId, $notes, $createdBy) {
            $violation = Violation::create([
                'rule_id'         => $rule->id,
                'subject_user_id' => $subjectUserId,
                'group_id'        => $groupId,
                'points_applied'  => (int)$rule->point_value,
                'notes'           => $notes,
                'created_by'      => $createdBy,
            ]);

            $this->updatePointCache($subjectUserId, $groupId, (int)$rule->point_value);

            Log::info('violation_recorded', [
                'violation_id' => $violation->id,
                'subject'      => $subjectUserId,
                'points'       => $rule->point_value,
            ]);

            return $violation;
        });
    }

    public function updatePointCache(int $userId, ?int $groupId, int $delta): void
    {
        $this->upsertPointCache('user_point_cache', 'user_id', $userId, $delta);

        if ($groupId !== null) {
            $this->upsertPointCache('group_point_cache', 'group_id', $groupId, $delta);
        }

        $this->checkThresholds($userId, $groupId);
    }

    /**
     * Increment (or create) a point-cache row. think-orm has no insertOrUpdate(),
     * so check-then-update/insert. total_points and last_alert_threshold are
     * NOT NULL with no default, so a fresh row must set both.
     */
    private function upsertPointCache(string $table, string $keyCol, int $keyVal, int $delta): void
    {
        $now    = date('Y-m-d H:i:s');
        $exists = Db::table($table)->where($keyCol, $keyVal)->find();
        if ($exists) {
            Db::table($table)->where($keyCol, $keyVal)->update([
                'total_points' => Db::raw('total_points + ' . $delta),
                'updated_at'   => $now,
            ]);
        } else {
            Db::table($table)->insert([
                $keyCol                => $keyVal,
                'total_points'         => $delta,
                'last_alert_threshold' => 0,
                'updated_at'           => $now,
            ]);
        }
    }

    public function checkThresholds(int $userId, ?int $groupId): void
    {
        $cache = UserPointCache::where('user_id', $userId)->find();
        if (!$cache) return;

        $total    = (int)$cache->total_points;
        $lastAlert = (int)$cache->last_alert_threshold;

        if ($total >= 50 && $lastAlert < 50) {
            $this->notifyAdmins("User #{$userId} has reached 50 violation points — administrative action required", 'violation_threshold_50', 'user', $userId);
            UserPointCache::where('user_id', $userId)->update(['last_alert_threshold' => 50]);
        } elseif ($total >= 25 && $lastAlert < 25) {
            $this->notifyTeamLeads("User #{$userId} has reached 25 violation points — manager review required", 'violation_threshold_25', 'user', $userId);
            UserPointCache::where('user_id', $userId)->update(['last_alert_threshold' => 25]);
        }
    }

    public function attachEvidence(int $violationId, string $tmpPath, string $originalName, int $userId): ViolationEvidence
    {
        $violation = Violation::find($violationId);
        if (!$violation) throw new NotFoundException('Violation not found');

        // Validate file size
        $size = filesize($tmpPath);
        if ($size > self::MAX_BYTES) {
            throw new AppException('File exceeds maximum allowed size of 10 MB');
        }

        // Validate MIME type
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpPath);
        if (!in_array($mimeType, self::MIME_WHITELIST, true)) {
            throw new AppException('Invalid file type. Only JPG, PNG, and PDF are allowed');
        }

        // Determine extension
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === 'jpeg') $ext = 'jpg';
        if (!in_array($ext, self::ALLOWED_EXTS, true)) {
            throw new AppException('Invalid file extension');
        }

        // SHA-256 fingerprint
        $sha256 = hash_file('sha256', $tmpPath);

        // Move file
        $destDir  = '/app/public/uploads/violations/' . $violationId;
        if (!is_dir($destDir)) mkdir($destDir, 0755, true);
        $filename = $sha256 . '.' . $ext;
        $destPath = $destDir . '/' . $filename;
        rename($tmpPath, $destPath);

        $evidence = ViolationEvidence::create([
            'violation_id'    => $violationId,
            'file_path'       => '/uploads/violations/' . $violationId . '/' . $filename,
            'file_type'       => $ext === 'jpeg' ? 'jpg' : $ext,
            'file_size_bytes' => $size,
            'sha256_hash'     => $sha256,
            'uploaded_by'     => $userId,
        ]);

        Log::info('evidence_attached', ['violation_id' => $violationId, 'sha256' => $sha256, 'by' => $userId]);
        return $evidence;
    }

    public function submitAppeal(int $violationId, string $reason, int $appellantId): ViolationAppeal
    {
        $violation = Violation::find($violationId);
        if (!$violation) throw new NotFoundException('Violation not found');

        if ($violation->subject_user_id !== $appellantId) {
            throw new \app\exception\ForbiddenException('You may only appeal your own violations');
        }

        if (ViolationAppeal::where('violation_id', $violationId)->count()) {
            throw new ConflictException('An appeal already exists for this violation');
        }

        $appeal = ViolationAppeal::create([
            'violation_id' => $violationId,
            'appellant_id' => $appellantId,
            'reason'       => $reason,
        ]);

        Violation::where('id', $violationId)->update(['status' => 'appealed']);
        return $appeal;
    }

    public function reviewAppeal(int $violationId, string $decision, string $notes, int $reviewerId): ViolationAppeal
    {
        $appeal = ViolationAppeal::where('violation_id', $violationId)->find();
        if (!$appeal) throw new NotFoundException('Appeal not found');
        if ($appeal->status === 'reviewed') throw new AppException('Appeal already reviewed');
        if (empty(trim($notes))) throw new AppException('Decision notes are required');

        ViolationAppeal::where('id', $appeal->id)->update([
            'status'         => 'reviewed',
            'reviewer_id'    => $reviewerId,
            'decision_notes' => $notes,
            'reviewed_at'    => date('Y-m-d H:i:s'),
        ]);

        $newStatus = ($decision === 'approved') ? 'reversed' : 'upheld';
        Violation::where('id', $violationId)->update(['status' => $newStatus]);

        if ($decision === 'approved') {
            // Reverse the points
            $violation = Violation::find($violationId);
            $this->updatePointCache((int)$violation->subject_user_id, $violation->group_id, -(int)$violation->points_applied);
        }

        return ViolationAppeal::find($appeal->id);
    }

    public function reReviewAppeal(int $violationId, string $decision, string $notes, int $reviewerId): ViolationAppealReview
    {
        $appeal = ViolationAppeal::where('violation_id', $violationId)->find();
        if (!$appeal) throw new NotFoundException('Appeal not found');
        if ($appeal->status !== 'reviewed') {
            throw new AppException('Appeal has not been initially reviewed; use the initial review endpoint');
        }
        if (empty(trim($notes))) throw new AppException('Decision notes are required');

        $review = ViolationAppealReview::create([
            'appeal_id'      => $appeal->id,
            'reviewer_id'    => $reviewerId,
            'decision'       => $decision,
            'decision_notes' => $notes,
        ]);

        $newStatus = ($decision === 'approved') ? 'reversed' : 'upheld';
        Violation::where('id', $violationId)->update(['status' => $newStatus]);

        if ($decision === 'approved') {
            $violation = Violation::find($violationId);
            $this->updatePointCache((int)$violation->subject_user_id, $violation->group_id, -(int)$violation->points_applied);
        }

        Log::info('appeal_re_reviewed', [
            'violation_id' => $violationId,
            'decision'     => $decision,
            'reviewer'     => $reviewerId,
        ]);

        return $review;
    }

    private function notifyAdmins(string $message, string $type, string $entityType, int $entityId): void
    {
        $admins = User::where('role', 'admin')->column('id');
        foreach ($admins as $adminId) {
            Notification::create(['recipient_id' => $adminId, 'type' => $type, 'message' => $message, 'entity_type' => $entityType, 'entity_id' => $entityId]);
        }
    }

    private function notifyTeamLeads(string $message, string $type, string $entityType, int $entityId): void
    {
        $leads = User::where('role', 'team_lead')->column('id');
        foreach ($leads as $leadId) {
            Notification::create(['recipient_id' => $leadId, 'type' => $type, 'message' => $message, 'entity_type' => $entityType, 'entity_id' => $entityId]);
        }
    }
}
