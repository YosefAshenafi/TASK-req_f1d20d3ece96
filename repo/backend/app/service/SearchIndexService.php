<?php
declare(strict_types=1);
namespace app\service;

use app\model\Activity;
use app\model\ActivityTag;
use think\facade\Db;
use think\facade\Log;

class SearchIndexService
{
    public function indexActivity(int $activityId): void
    {
        $activity = Activity::with(['author', 'tags'])->find($activityId);
        if (!$activity) return;

        $tags        = ActivityTag::where('activity_id', $activityId)->column('tag');
        $authorName  = $activity->author ? $activity->author->username : '';
        $signupCount = Db::table('activity_signups')->where('activity_id', $activityId)->where('status', 'active')->count();

        $data = [
            'entity_type'  => 'activity',
            'entity_id'    => $activityId,
            'title'        => $activity->title,
            'body'         => $activity->body,
            'author_name'  => $authorName,
            'tags'         => json_encode($tags, JSON_UNESCAPED_UNICODE),
            'tags_text'    => implode(' ', $tags), // denormalized for FULLTEXT inclusion
            'view_count'   => (int)$activity->view_count,
            'reply_count'  => (int)$activity->reply_count,
            'signup_count' => $signupCount,
            'family_id'    => $activity->getData('family_id') ?? ('activity:' . $activityId),
            'indexed_at'   => date('Y-m-d H:i:s'),
        ];

        $existing = Db::table('search_index')
            ->where('entity_type', 'activity')
            ->where('entity_id', $activityId)
            ->find();

        if ($existing) {
            Db::table('search_index')->where('id', $existing['id'])->update($data);
        } else {
            Db::table('search_index')->insert($data);
        }
    }

    public function indexOrder(int $orderId): void
    {
        $order = Db::table('orders')->where('id', $orderId)->find();
        if (!$order) return;

        $displayName = "Order #{$orderId}: {$order['type']}";
        if (!empty($order['description'])) {
            $displayName .= ' — ' . mb_substr($order['description'], 0, 100);
        }

        $correctionCount = Db::table('invoice_corrections')
            ->where('order_id', $orderId)
            ->count();

        $existing = Db::table('logistics_index')
            ->where('entity_type', 'order')
            ->where('entity_id', $orderId)
            ->find();

        $familyId = $order['family_id'] ?? ('order:' . $orderId);

        if ($existing) {
            // Preserve view_count (tracked externally); refresh display fields, reply_count, and family_id
            Db::table('logistics_index')->where('id', $existing['id'])->update([
                'display_name' => $displayName,
                'pinyin_name'  => $this->computePinyin($displayName),
                'reply_count'  => $correctionCount,
                'family_id'    => $familyId,
                'indexed_at'   => date('Y-m-d H:i:s'),
            ]);
        } else {
            Db::table('logistics_index')->insert([
                'entity_type'  => 'order',
                'entity_id'    => $orderId,
                'display_name' => $displayName,
                'pinyin_name'  => $this->computePinyin($displayName),
                'view_count'   => 0,
                'reply_count'  => $correctionCount,
                'family_id'    => $familyId,
                'indexed_at'   => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function indexShipment(int $shipmentId): void
    {
        $shipment = Db::table('shipments')->where('id', $shipmentId)->find();
        if (!$shipment) return;

        $packages   = Db::table('shipment_packages')->where('shipment_id', $shipmentId)->select()->toArray();
        $carriers   = array_filter(array_unique(array_column($packages, 'carrier_name')));
        $carrierStr = !empty($carriers) ? implode(', ', $carriers) : '';

        $displayName = "Shipment #{$shipmentId} (Order #{$shipment['order_id']})";
        if ($carrierStr) {
            $displayName .= " via {$carrierStr}";
        }

        $eventCount = Db::table('shipment_events')
            ->where('shipment_id', $shipmentId)
            ->count();

        $existing = Db::table('logistics_index')
            ->where('entity_type', 'shipment')
            ->where('entity_id', $shipmentId)
            ->find();

        if ($existing) {
            // Preserve view_count; refresh display fields and reply_count from shipment events
            Db::table('logistics_index')->where('id', $existing['id'])->update([
                'display_name' => $displayName,
                'pinyin_name'  => $this->computePinyin($displayName),
                'reply_count'  => $eventCount,
                'indexed_at'   => date('Y-m-d H:i:s'),
            ]);
        } else {
            Db::table('logistics_index')->insert([
                'entity_type'  => 'shipment',
                'entity_id'    => $shipmentId,
                'display_name' => $displayName,
                'pinyin_name'  => $this->computePinyin($displayName),
                'view_count'   => 0,
                'reply_count'  => $eventCount,
                'indexed_at'   => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function deleteIndex(string $entityType, int $entityId): void
    {
        try {
            Db::transaction(function () use ($entityType, $entityId) {
                Db::table('search_index')->where('entity_type', $entityType)->where('entity_id', $entityId)->delete();
                Db::table('logistics_index')->where('entity_type', $entityType)->where('entity_id', $entityId)->delete();
                Db::table('index_orphan_candidates')->insert([
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                    'deleted_at'  => date('Y-m-d H:i:s'),
                ]);
            });
        } catch (\Throwable $e) {
            Log::warning('delete_index_failed', ['type' => $entityType, 'id' => $entityId, 'error' => $e->getMessage()]);
        }
    }

    public function globalSearch(string $query, array $filters, string $sort, int $page, int $perPage, string $role): array
    {
        $sanitized = str_replace(['"', "'", '\\'], '', $query);
        $ftQuery   = '+' . implode('* +', explode(' ', trim($sanitized))) . '*';

        // Inline the fulltext term as a quoted literal rather than a bound param.
        // A positional "?" inside field() is not converted to a bind (field()'s
        // 2nd arg is $except), while whereRaw()'s "?" becomes a named param —
        // mixing the two triggers SQLSTATE[HY093]. $ftQuery is safe to inline:
        // quotes and backslashes were stripped from $sanitized above, so it
        // cannot break out of the single-quoted string literal.
        $ftLiteral = "'" . $ftQuery . "'";

        $builder = Db::table('search_index')
            ->fieldRaw("*, MATCH(title, body, author_name, tags_text) AGAINST({$ftLiteral} IN BOOLEAN MODE) AS relevance_score")
            ->whereRaw("MATCH(title, body, author_name, tags_text) AGAINST({$ftLiteral} IN BOOLEAN MODE)");

        $countBuilder = Db::table('search_index')
            ->whereRaw("MATCH(title, body, author_name, tags_text) AGAINST({$ftLiteral} IN BOOLEAN MODE)");

        // Regular users: only published activities
        if ($role === 'regular') {
            $builder->where('entity_type', 'activity')
                    ->whereRaw("entity_id IN (SELECT `id` FROM `activities` WHERE `status` = 'published')");
            $countBuilder->where('entity_type', 'activity')
                         ->whereRaw("entity_id IN (SELECT `id` FROM `activities` WHERE `status` = 'published')");
        }

        // Filters
        if (!empty($filters['entity_type'])) {
            $builder->where('entity_type', $filters['entity_type']);
            $countBuilder->where('entity_type', $filters['entity_type']);
        }
        if (!empty($filters['date_from'])) {
            $builder->where('indexed_at', '>=', $filters['date_from']);
            $countBuilder->where('indexed_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $builder->where('indexed_at', '<=', $filters['date_to']);
            $countBuilder->where('indexed_at', '<=', $filters['date_to']);
        }

        // Sorting
        match ($sort) {
            'recency'     => $builder->order('indexed_at', 'desc'),
            'popularity'  => $builder->order('view_count', 'desc'),
            'reply_count' => $builder->order('reply_count', 'desc'),
            default       => $builder->order('relevance_score', 'desc'),
        };

        $results = $builder->page($page, $perPage)->select();

        // Add highlight to each result (title, body excerpt, and matching tags)
        $highlighted = [];
        foreach ($results as $row) {
            $row['highlight'] = [
                'title'     => $this->highlight($row['title'] ?? '', $query),
                'body'      => $this->highlight(mb_substr($row['body'] ?? '', 0, 200), $query),
                'tags_text' => $this->highlight($row['tags_text'] ?? '', $query),
            ];
            $highlighted[] = $row;
        }

        $total = $countBuilder->count();

        return ['data' => $highlighted, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function logisticsSearch(string $query, array $filters, string $sort, int $page, int $perPage, bool $usePinyin, bool $useSynonyms, int $userId, string $userRole): array
    {
        // Tokenize
        $rawTokens = preg_split('/[\s\p{P}]+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY);

        // Spell correction — track originals to detect corrections for scoring purposes
        $correctedTokens = array_map(fn($t) => $this->spellCorrect($t), $rawTokens);

        // Synonym expansion — kept separate so synonym hits score lower than primary hits
        $synonymTokens = [];
        if ($useSynonyms) {
            foreach ($correctedTokens as $token) {
                $row = Db::table('synonym_map')->where('term', strtolower($token))->find();
                if ($row) {
                    $syns = json_decode($row['synonyms'], true) ?? [];
                    foreach (array_slice($syns, 0, 3) as $syn) {
                        if (!in_array($syn, $correctedTokens, true)) {
                            $synonymTokens[] = $syn;
                        }
                    }
                }
            }
            $synonymTokens = array_unique($synonymTokens);
        }

        $allTokens = array_unique(array_merge($correctedTokens, $synonymTokens));

        // Build WHERE conditions (any token in any searchable field → include row)
        $conditions = [];
        $bindings   = [];
        foreach ($allTokens as $token) {
            if (mb_strlen($token) >= 3) {
                $conditions[] = 'display_name LIKE ?';
                $bindings[]   = '%' . $token . '%';
                if ($usePinyin) {
                    $conditions[] = 'pinyin_name LIKE ?';
                    $bindings[]   = '%' . $token . '%';
                }
            }
        }

        // Relevance sort: fetch all matches, score in PHP, sort, paginate in PHP
        if ($sort === 'relevance') {
            return $this->logisticsRelevanceSearch(
                $conditions, $bindings, $filters, $page, $perPage,
                $correctedTokens, $rawTokens, $synonymTokens, $usePinyin, $userId, $userRole
            );
        }

        // Non-relevance sorts: SQL ORDER BY + SQL pagination
        $builder = Db::table('logistics_index');
        if (!empty($conditions)) {
            $builder->whereRaw('(' . implode(' OR ', $conditions) . ')', $bindings);
        }
        if (!empty($filters['entity_type']) && in_array($filters['entity_type'], ['order', 'shipment'], true)) {
            $builder->where('entity_type', $filters['entity_type']);
        }
        $this->applyLogisticsAuthFilter($builder, $userId, $userRole);

        match ($sort) {
            'recency'     => $builder->order('indexed_at', 'desc'),
            'popularity'  => $builder->order('view_count', 'desc'),
            'reply_count' => $builder->order('reply_count', 'desc'),
        };
        // Deterministic tiebreaker: indexed_at is second-precision, so rows
        // created within the same second would otherwise tie. The later-inserted
        // index row has the higher id, so id DESC keeps "more recent" first.
        $builder->order('id', 'desc');

        $results = $builder->page($page, $perPage)->select();

        $countBuilder = Db::table('logistics_index');
        if (!empty($conditions)) {
            $countBuilder->whereRaw('(' . implode(' OR ', $conditions) . ')', $bindings);
        }
        if (!empty($filters['entity_type']) && in_array($filters['entity_type'], ['order', 'shipment'], true)) {
            $countBuilder->where('entity_type', $filters['entity_type']);
        }
        $this->applyLogisticsAuthFilter($countBuilder, $userId, $userRole);
        $total = $countBuilder->count();

        return ['data' => $results->toArray(), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Fetch all matching logistics rows, score each by token-hit weight, sort, then slice for the page.
     * Uses PHP-side scoring because LIKE has no inherent relevance score.
     */
    private function logisticsRelevanceSearch(
        array $conditions, array $bindings, array $filters,
        int $page, int $perPage,
        array $correctedTokens, array $rawTokens, array $synonymTokens, bool $usePinyin,
        int $userId, string $userRole
    ): array {
        $builder = Db::table('logistics_index');
        if (!empty($conditions)) {
            $builder->whereRaw('(' . implode(' OR ', $conditions) . ')', $bindings);
        }
        if (!empty($filters['entity_type']) && in_array($filters['entity_type'], ['order', 'shipment'], true)) {
            $builder->where('entity_type', $filters['entity_type']);
        }
        $this->applyLogisticsAuthFilter($builder, $userId, $userRole);

        $allRows = $builder->select()->toArray();

        foreach ($allRows as &$row) {
            $row['relevance_score'] = $this->scoreLogisticsRow(
                $row, $correctedTokens, $rawTokens, $synonymTokens, $usePinyin
            );
        }
        unset($row);

        usort($allRows, fn($a, $b) => $b['relevance_score'] <=> $a['relevance_score']);

        $total  = count($allRows);
        $offset = ($page - 1) * $perPage;
        $paged  = array_slice($allRows, $offset, $perPage);

        return ['data' => array_values($paged), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Restrict logistics_index results to entities the caller is authorized to see.
     * Admin: unrestricted. Non-admin: orders and shipments owned by the caller (created_by = userId).
     */
    private function applyLogisticsAuthFilter(object $builder, int $userId, string $userRole): void
    {
        if ($userRole === 'admin') {
            return;
        }
        $builder->whereRaw(
            '((entity_type = \'order\' AND entity_id IN (SELECT `id` FROM `orders` WHERE `created_by` = ?))'
            . ' OR (entity_type = \'shipment\' AND entity_id IN (SELECT `id` FROM `shipments` WHERE `created_by` = ?)))',
            [$userId, $userId]
        );
    }

    /**
     * Token-hit relevance score for a single logistics_index row.
     * Weights: exact primary token = 2.0, spell-corrected token = 1.5,
     *          pinyin match = 1.0, synonym expansion = 0.5.
     */
    private function scoreLogisticsRow(array $row, array $correctedTokens, array $rawTokens, array $synonymTokens, bool $usePinyin): float
    {
        $score      = 0.0;
        $displayLow = mb_strtolower($row['display_name'] ?? '');
        $pinyinLow  = $usePinyin ? mb_strtolower($row['pinyin_name'] ?? '') : '';

        foreach ($correctedTokens as $i => $token) {
            if (mb_strlen($token) < 3) continue;
            $tokenLow  = mb_strtolower($token);
            $rawLow    = mb_strtolower($rawTokens[$i] ?? $token);
            $hitWeight = ($tokenLow === $rawLow) ? 2.0 : 1.5; // spell-corrected hit is slightly penalised

            if (str_contains($displayLow, $tokenLow)) {
                $score += $hitWeight;
            }
            if ($usePinyin && $pinyinLow !== '' && str_contains($pinyinLow, $tokenLow)) {
                $score += 1.0;
            }
        }

        foreach ($synonymTokens as $token) {
            if (mb_strlen($token) < 3) continue;
            if (str_contains($displayLow, mb_strtolower($token))) {
                $score += 0.5;
            }
        }

        return $score;
    }

    private function highlight(string $text, string $query): string
    {
        $terms = array_filter(explode(' ', preg_quote($query, '/')));
        foreach ($terms as $term) {
            if (strlen($term) >= 2) {
                $text = preg_replace('/(' . $term . ')/iu', '<mark>$1</mark>', $text);
            }
        }
        return $text;
    }

    private function spellCorrect(string $token): string
    {
        if (mb_strlen($token) < 4) return $token;
        $terms = Db::table('synonym_map')->column('term');
        $best  = $token;
        $minDist = PHP_INT_MAX;
        foreach ($terms as $term) {
            $dist = levenshtein(strtolower($token), strtolower($term));
            if ($dist < $minDist && $dist <= 2) {
                $minDist = $dist;
                $best    = $term;
            }
        }
        return $best;
    }

    /**
     * Basic CJK → pinyin mapping for common characters.
     * In production, replace with overtrue/pinyin library.
     */
    private function computePinyin(string $text): string
    {
        static $map = [
            '设备' => 'shebei', '活动' => 'huodong', '租赁' => 'zulin',
            '打印' => 'dayin',  '材料' => 'cailiao', '人员' => 'renyuan',
            '团队' => 'tuandui','管理' => 'guanli',  '系统' => 'xitong',
        ];
        $pinyin = $text;
        foreach ($map as $zh => $py) {
            $pinyin = str_replace($zh, $py, $pinyin);
        }
        return $pinyin;
    }
}
