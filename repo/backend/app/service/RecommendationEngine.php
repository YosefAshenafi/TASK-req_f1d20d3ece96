<?php
declare(strict_types=1);
namespace app\service;

use think\facade\Db;
use think\facade\Log;

class RecommendationEngine
{
    private const CACHE_TTL_SECONDS = 3600; // 1 hour
    private const MAX_TAG_FRACTION  = 0.40; // 40% diversity cap

    public function compute(int $userId, string $userRole, string $context, ?int $contextEntityId, int $limit, ?string $contextEntityType = null): array
    {
        // 1. Check cache
        $cached = Db::table('recommendation_cache')
            ->where('user_id', $userId)
            ->where('context', $context)
            ->where('context_entity_id', $contextEntityId)
            ->where('computed_at', '>=', date('Y-m-d H:i:s', time() - self::CACHE_TTL_SECONDS))
            ->find();

        if ($cached) {
            return ['items' => json_decode($cached['items'], true) ?? [], 'is_cold_start' => false];
        }

        // 2. Get user signal vector
        $tracker = new BehaviorTracker();
        $signals = $tracker->getUserSignalVector($userId);

        if (empty($signals)) {
            $items = $this->coldStart($userId, $userRole, $limit);
            $this->storeCache($userId, $context, $contextEntityId, $items);
            return ['items' => $items, 'is_cold_start' => true];
        }

        // 3. Score candidates: published activities + authorized order candidates
        $activities = Db::table('search_index')
            ->field('entity_id, title, tags, view_count, signup_count, family_id')
            ->where('entity_type', 'activity')
            ->select();

        $scored = [];
        foreach ($activities as $row) {
            // Entity-type-aware self-exclusion: only skip activities when context is activity-detail
            if ($contextEntityType === 'activity' && $contextEntityId !== null && (int)$row['entity_id'] === $contextEntityId) continue;

            $tags  = json_decode($row['tags'] ?? '[]', true) ?: [];
            $score = 0.0;
            foreach ($tags as $tag) {
                $score += $signals[$tag] ?? 0;
            }
            // Popularity bonus: log(view_count + 1) * 0.1
            $score += log((int)$row['view_count'] + 1) * 0.1;

            $scored[] = [
                'entity_id'   => (int)$row['entity_id'],
                'title'       => $row['title'],
                'tags'        => $tags,
                'score'       => $score,
                'entity_type' => 'activity',
                'family_id'   => $row['family_id'] ?: ('activity:' . (int)$row['entity_id']),
            ];
        }

        // Include authorized order candidates scored by view_count
        foreach ($this->fetchOrderRows($userId, $userRole) as $row) {
            // Entity-type-aware self-exclusion: only skip orders when context is order-detail
            if ($contextEntityType === 'order' && $contextEntityId !== null && (int)$row['entity_id'] === $contextEntityId) continue;
            $scored[] = [
                'entity_id'   => (int)$row['entity_id'],
                'title'       => $row['display_name'],
                'tags'        => [],
                'score'       => log((int)$row['view_count'] + 1) * 0.15,
                'entity_type' => 'order',
                'family_id'   => $row['family_id'] ?: ('order:' . (int)$row['entity_id']),
            ];
        }

        // 4. Sort by score descending
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        // 5. Deduplicate by entity key and by explicit stored family_id

        $seenEntityKeys = [];
        $seenFamilies   = [];
        $scored = array_filter($scored, function ($item) use (&$seenEntityKeys, &$seenFamilies) {
            $entityKey = $item['entity_type'] . ':' . $item['entity_id'];
            if (in_array($entityKey, $seenEntityKeys, true)) return false;
            if (in_array($item['family_id'], $seenFamilies, true)) return false;
            $seenEntityKeys[] = $entityKey;
            $seenFamilies[]   = $item['family_id'];
            return true;
        });

        // 6. Diversity cap: no single tag > 40% of $limit
        $items      = $this->applyDiversityCap(array_values($scored), $limit);

        // 7. Store cache
        $this->storeCache($userId, $context, $contextEntityId, $items);

        return ['items' => $items, 'is_cold_start' => false];
    }

    public function coldStart(int $userId, string $userRole, int $limit): array
    {
        $since  = date('Y-m-d', strtotime('-30 days'));
        $topTags = Db::table('tag_popularity')
            ->where('period_start', '>=', $since)
            ->order('score', 'desc')
            ->limit(10)
            ->column('tag');

        if (empty($topTags)) {
            // Fall back to most viewed activities + authorized orders
            $activities = Db::table('search_index')
                ->field('entity_id, title, tags, view_count, family_id')
                ->where('entity_type', 'activity')
                ->order('view_count', 'desc')
                ->limit($limit)
                ->select();

            $candidates = array_map(fn($r) => [
                'entity_id'   => (int)$r['entity_id'],
                'title'       => $r['title'],
                'tags'        => json_decode($r['tags'] ?? '[]', true) ?: [],
                'score'       => (float)$r['view_count'],
                'entity_type' => 'activity',
                'family_id'   => $r['family_id'] ?: ('activity:' . (int)$r['entity_id']),
            ], (array)$activities);

            foreach ($this->fetchOrderRows($userId, $userRole, $limit) as $r) {
                $candidates[] = [
                    'entity_id'   => (int)$r['entity_id'],
                    'title'       => $r['display_name'],
                    'tags'        => [],
                    'score'       => (float)$r['view_count'],
                    'entity_type' => 'order',
                    'family_id'   => $r['family_id'] ?: ('order:' . (int)$r['entity_id']),
                ];
            }

            usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
            return $this->applyDiversityCap(array_values($candidates), $limit);
        }

        // Build candidates from top tags (activities) + top-viewed authorized orders
        $candidates = [];
        foreach ($topTags as $tag) {
            $rows = Db::query(
                "SELECT entity_id, title, tags, view_count, family_id FROM search_index
                 WHERE entity_type = 'activity' AND JSON_CONTAINS(tags, JSON_QUOTE(?))
                 ORDER BY view_count DESC LIMIT 5",
                [$tag]
            );
            foreach ($rows as $row) {
                $candidates[] = [
                    'entity_id'   => (int)$row['entity_id'],
                    'title'       => $row['title'],
                    'tags'        => json_decode($row['tags'] ?? '[]', true) ?: [],
                    'score'       => (float)$row['view_count'],
                    'entity_type' => 'activity',
                    'family_id'   => $row['family_id'] ?: ('activity:' . (int)$row['entity_id']),
                ];
            }
        }

        // Supplement with top-viewed authorized orders
        foreach ($this->fetchOrderRows($userId, $userRole, $limit) as $r) {
            $candidates[] = [
                'entity_id'   => (int)$r['entity_id'],
                'title'       => $r['display_name'],
                'tags'        => [],
                'score'       => (float)$r['view_count'],
                'entity_type' => 'order',
                'family_id'   => $r['family_id'] ?: ('order:' . (int)$r['entity_id']),
            ];
        }

        // Deduplicate by entity key and by explicit stored family_id

        $seenEntityKeys = [];
        $seenFamilies   = [];
        $candidates = array_filter($candidates, function ($c) use (&$seenEntityKeys, &$seenFamilies) {
            $entityKey = $c['entity_type'] . ':' . $c['entity_id'];
            if (in_array($entityKey, $seenEntityKeys, true)) return false;
            if (in_array($c['family_id'], $seenFamilies, true)) return false;
            $seenEntityKeys[] = $entityKey;
            $seenFamilies[]   = $c['family_id'];
            return true;
        });

        return $this->applyDiversityCap(array_values($candidates), $limit);
    }

    public function recomputeTagPopularity(): void
    {
        $since = date('Y-m-d H:i:s', strtotime('-30 days'));
        $today = date('Y-m-d');

        try {
            $rows = Db::query(
                "SELECT JSON_UNQUOTE(JSON_EXTRACT(si.tags, CONCAT('$[', numbers.n, ']'))) AS tag,
                        SUM(CASE WHEN be.event_type = 'signup' THEN 1 ELSE 0 END) AS signup_count,
                        SUM(CASE WHEN be.event_type = 'view'   THEN 1 ELSE 0 END) AS view_count
                 FROM behavior_events be
                 JOIN search_index si ON si.entity_type = 'activity' AND si.entity_id = be.entity_id,
                 (SELECT 0 AS n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4) numbers
                 WHERE be.occurred_at >= ?
                   AND JSON_EXTRACT(si.tags, CONCAT('$[', numbers.n, ']')) IS NOT NULL
                 GROUP BY tag",
                [$since]
            );

            foreach ($rows as $row) {
                if (empty($row['tag'])) continue;
                $score = 0.6 * $row['signup_count'] + 0.4 * $row['view_count'];
                Db::table('tag_popularity')->insertOrUpdate(
                    ['tag' => $row['tag'], 'period_start' => $today],
                    ['signup_count' => $row['signup_count'], 'view_count' => $row['view_count'], 'score' => $score]
                );
            }

            Log::info('tag_popularity_recomputed', ['date' => $today, 'tags' => count($rows)]);
        } catch (\Throwable $e) {
            Log::error('tag_popularity_recompute_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Returns order rows from logistics_index filtered by caller authorization.
     * Admin: all orders. Non-admin: only orders whose orders.created_by = $userId.
     * Mirrors the visibility policy enforced by Order::index() and Order::show().
     */
    private function fetchOrderRows(int $userId, string $userRole, ?int $limit = null): array
    {
        if ($userRole === 'admin') {
            $query = Db::table('logistics_index')
                ->field('entity_id, display_name, view_count, family_id')
                ->where('entity_type', 'order')
                ->order('view_count', 'desc');
            if ($limit !== null) {
                $query->limit($limit);
            }
            return (array)$query->select();
        }

        // Non-admin: join with orders table to enforce created_by ownership
        $sql = "SELECT li.entity_id, li.display_name, li.view_count, li.family_id
                FROM logistics_index li
                INNER JOIN orders o ON o.id = li.entity_id
                WHERE li.entity_type = 'order'
                  AND o.created_by = ?
                ORDER BY li.view_count DESC";
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit;
        }
        return Db::query($sql, [$userId]);
    }

    /**
     * Stable family identifier for a recommendation candidate.
     * Activities: 'tag:<lex-first-tag>' or 'activity:<id>' when untagged.
     * Orders and other types: '<entity_type>:<entity_id>'.
     */
    private function computeFamilyId(array $item): string
    {
        if (!empty($item['tags'])) {
            $sorted = $item['tags'];
            sort($sorted);
            return 'tag:' . $sorted[0];
        }
        return $item['entity_type'] . ':' . $item['entity_id'];
    }

    private function applyDiversityCap(array $items, int $limit): array
    {
        $selected  = [];
        $tagCounts = [];
        $maxPerTag = (int)ceil($limit * self::MAX_TAG_FRACTION);

        foreach ($items as $item) {
            if (count($selected) >= $limit) break;

            $tags = $item['tags'];
            $fits = true;
            foreach ($tags as $tag) {
                if (($tagCounts[$tag] ?? 0) >= $maxPerTag) {
                    $fits = false;
                    break;
                }
            }

            if ($fits) {
                foreach ($tags as $tag) {
                    $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
                }
                $selected[] = $item;
            }
        }

        return $selected;
    }

    private function storeCache(int $userId, string $context, ?int $contextEntityId, array $items): void
    {
        try {
            Db::table('recommendation_cache')->where('user_id', $userId)->where('context', $context)->where('context_entity_id', $contextEntityId)->delete();
            Db::table('recommendation_cache')->insert([
                'user_id'           => $userId,
                'context'           => $context,
                'context_entity_id' => $contextEntityId,
                'items'             => json_encode($items, JSON_UNESCAPED_UNICODE),
                'computed_at'       => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('rec_cache_store_failed', ['error' => $e->getMessage()]);
        }
    }
}
