<?php
declare(strict_types=1);
namespace app\service;

use think\facade\Db;
use think\facade\Log;

class BehaviorTracker
{
    private const WEIGHTS = ['view' => 1, 'save' => 3, 'signup' => 5];
    private const VIEW_DEDUP_SECONDS = 1800; // 30 minutes

    public function record(int $userId, string $entityType, int $entityId, string $eventType): void
    {
        try {
            // Dedup logic for views: skip if already viewed within 30 minutes
            if ($eventType === 'view') {
                $recent = Db::table('behavior_events')
                    ->where('user_id', $userId)
                    ->where('entity_type', $entityType)
                    ->where('entity_id', $entityId)
                    ->where('event_type', 'view')
                    ->where('occurred_at', '>=', date('Y-m-d H:i:s', time() - self::VIEW_DEDUP_SECONDS))
                    ->count();

                if ($recent > 0) return; // skip duplicate view
            }

            Db::table('behavior_events')->insert([
                'user_id'     => $userId,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'event_type'  => $eventType,
                'occurred_at' => date('Y-m-d H:i:s'),
            ]);

            // Invalidate recommendation cache for this user
            Db::table('recommendation_cache')->where('user_id', $userId)->delete();
        } catch (\Throwable $e) {
            Log::warning('behavior_record_failed', ['error' => $e->getMessage()]);
        }
    }

    public function getUserSignalVector(int $userId, int $days = 30): array
    {
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // JOIN behavior_events -> search_index -> tags (stored in search_index.tags JSON)
        $rows = Db::query(
            "SELECT si.tags, be.event_type, COUNT(*) as cnt
             FROM behavior_events be
             JOIN search_index si ON si.entity_type = be.entity_type AND si.entity_id = be.entity_id
             WHERE be.user_id = ? AND be.occurred_at >= ? AND be.entity_type = 'activity'
             GROUP BY si.tags, be.event_type",
            [$userId, $since]
        );

        $scores = [];
        foreach ($rows as $row) {
            $tags   = json_decode($row['tags'] ?? '[]', true) ?: [];
            $weight = self::WEIGHTS[$row['event_type']] ?? 1;
            foreach ($tags as $tag) {
                $scores[$tag] = ($scores[$tag] ?? 0) + ($weight * $row['cnt']);
            }
        }

        if (empty($scores)) return [];

        // Normalize to [0, 1]
        $max = max($scores);
        if ($max > 0) {
            foreach ($scores as &$s) { $s /= $max; }
        }

        arsort($scores);
        return $scores;
    }
}
