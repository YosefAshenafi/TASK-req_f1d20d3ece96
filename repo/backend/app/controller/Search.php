<?php
declare(strict_types=1);
namespace app\controller;

use think\Request;
use app\service\SearchIndexService;

class Search
{
    private SearchIndexService $service;
    public function __construct() { $this->service = new SearchIndexService(); }

    public function globalSearch(Request $request)
    {
        $q    = trim($request->get('q', ''));
        $sort = $request->get('sort', 'relevance');

        if (mb_strlen($q) < 2) {
            return json(['code' => 422, 'msg' => 'Search query must be at least 2 characters', 'errors' => ['q' => 'too short']], 422);
        }

        $validSorts = ['relevance', 'recency', 'popularity', 'reply_count'];
        if (!in_array($sort, $validSorts, true)) {
            return json(['code' => 422, 'msg' => 'Invalid sort value', 'errors' => ['sort' => 'must be one of: ' . implode(', ', $validSorts)]], 422);
        }

        $filters = [
            'entity_type' => $request->get('entity_type', ''),
            'date_from'   => $request->get('date_from', ''),
            'date_to'     => $request->get('date_to', ''),
        ];

        $page    = max(1, (int)$request->get('page', 1));
        $perPage = min(50, max(1, (int)$request->get('per_page', 20)));

        $results = $this->service->globalSearch($q, $filters, $sort, $page, $perPage, $request->user_role);
        return json(['code' => 200, 'msg' => 'ok', 'data' => $results]);
    }

    public function logisticsSearch(Request $request)
    {
        $q    = trim($request->get('q', ''));
        $sort = $request->get('sort', 'recency');

        if (mb_strlen($q) < 2) {
            return json(['code' => 422, 'msg' => 'Search query must be at least 2 characters', 'errors' => ['q' => 'too short']], 422);
        }

        $validSorts = ['recency', 'relevance'];
        if (!in_array($sort, $validSorts, true)) {
            return json(['code' => 422, 'msg' => 'Invalid sort value', 'errors' => ['sort' => 'invalid']], 422);
        }

        $usePinyin   = filter_var($request->get('use_pinyin', 'false'), FILTER_VALIDATE_BOOLEAN);
        $useSynonyms = filter_var($request->get('use_synonyms', 'false'), FILTER_VALIDATE_BOOLEAN);

        $filters = ['entity_type' => $request->get('entity_type', '')];
        $page    = max(1, (int)$request->get('page', 1));
        $perPage = min(50, (int)$request->get('per_page', 20));

        $results = $this->service->logisticsSearch($q, $filters, $sort, $page, $perPage, $usePinyin, $useSynonyms);
        return json(['code' => 200, 'msg' => 'ok', 'data' => $results]);
    }
}
