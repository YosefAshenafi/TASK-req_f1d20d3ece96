<?php
declare(strict_types=1);
namespace app\service;

use app\model\Activity;
use app\model\ActivityTag;
use app\model\User;
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
            'view_count'   => (int)$activity->view_count,
            'signup_count' => $signupCount,
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

        // Also index into logistics_index if it has a description worth searching
        $this->indexOrderOrActivity($activityId, 'activity', $activity->title);
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

        $builder = Db::table('search_index')
            ->field('*, MATCH(title, body, author_name) AGAINST(? IN BOOLEAN MODE) AS relevance_score', [$ftQuery])
            ->whereRaw('MATCH(title, body, author_name) AGAINST(? IN BOOLEAN MODE)', [$ftQuery]);

        // Role-based scoping: regular users only see activity (no draft/archived)
        if ($role === 'regular') {
            $builder->where('entity_type', 'activity');
        }

        // Filters
        if (!empty($filters['entity_type'])) {
            $builder->where('entity_type', $filters['entity_type']);
        }
        if (!empty($filters['date_from'])) {
            $builder->where('indexed_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $builder->where('indexed_at', '<=', $filters['date_to']);
        }

        // Sorting
        match ($sort) {
            'recency'     => $builder->order('indexed_at', 'desc'),
            'popularity'  => $builder->order('view_count', 'desc'),
            'reply_count' => $builder->order('reply_count', 'desc'),
            default       => $builder->order('relevance_score', 'desc'),
        };

        $results = $builder->page($page, $perPage)->select();

        // Add highlight to each result
        $highlighted = [];
        foreach ($results as $row) {
            $row['highlight'] = [
                'title' => $this->highlight($row['title'] ?? '', $query),
                'body'  => $this->highlight(mb_substr($row['body'] ?? '', 0, 200), $query),
            ];
            $highlighted[] = $row;
        }

        $total = Db::table('search_index')
            ->whereRaw('MATCH(title, body, author_name) AGAINST(? IN BOOLEAN MODE)', [$ftQuery])
            ->count();

        return ['data' => $highlighted, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function logisticsSearch(string $query, array $filters, string $sort, int $page, int $perPage, bool $usePinyin, bool $useSynonyms): array
    {
        // Tokenize
        $tokens = preg_split('/[\s\p{P}]+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY);

        // Spell correction via Levenshtein against known terms
        $tokens = array_map(fn($t) => $this->spellCorrect($t), $tokens);

        // Synonym expansion
        if ($useSynonyms) {
            $expanded = [];
            foreach ($tokens as $token) {
                $expanded[] = $token;
                $row = Db::table('synonym_map')->where('term', strtolower($token))->find();
                if ($row) {
                    $syns = json_decode($row['synonyms'], true) ?? [];
                    $expanded = array_merge($expanded, array_slice($syns, 0, 3));
                }
            }
            $tokens = array_unique($expanded);
        }

        $builder = Db::table('logistics_index');

        // Build OR conditions across display_name (and pinyin_name if enabled)
        $conditions = [];
        $bindings   = [];
        foreach ($tokens as $token) {
            if (mb_strlen($token) >= 3) {
                $conditions[] = 'display_name LIKE ?';
                $bindings[]   = '%' . $token . '%';
                if ($usePinyin) {
                    $conditions[] = 'pinyin_name LIKE ?';
                    $bindings[]   = '%' . $token . '%';
                }
            }
        }

        if (!empty($conditions)) {
            $builder->whereRaw('(' . implode(' OR ', $conditions) . ')', $bindings);
        }

        // Filters
        if (!empty($filters['entity_type'])) {
            $builder->where('entity_type', $filters['entity_type']);
        }

        match ($sort) {
            'recency' => $builder->order('indexed_at', 'desc'),
            default   => $builder->order('indexed_at', 'desc'),
        };

        $results = $builder->page($page, $perPage)->select();
        $total   = Db::table('logistics_index')->count(); // simplified count

        return ['data' => $results, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
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

    private function indexOrderOrActivity(int $entityId, string $type, string $name): void
    {
        // Only index activities in logistics index for now
        if ($type !== 'activity') return;
        $pinyin = $this->computePinyin($name);
        $data   = ['entity_type' => $type, 'entity_id' => $entityId, 'display_name' => $name, 'pinyin_name' => $pinyin, 'indexed_at' => date('Y-m-d H:i:s')];
        $exists = Db::table('logistics_index')->where('entity_type', $type)->where('entity_id', $entityId)->find();
        if ($exists) {
            Db::table('logistics_index')->where('id', $exists['id'])->update($data);
        } else {
            Db::table('logistics_index')->insert($data);
        }
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
