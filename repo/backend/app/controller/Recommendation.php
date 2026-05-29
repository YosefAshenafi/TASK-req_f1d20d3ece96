<?php
declare(strict_types=1);
namespace app\controller;

use think\Request;
use app\service\RecommendationEngine;

class Recommendation
{
    private RecommendationEngine $engine;
    public function __construct() { $this->engine = new RecommendationEngine(); }

    public function listRecommendations(Request $request)
    {
        $limit  = min(50, max(1, (int)$request->get('page_size', 10)));
        $result = $this->engine->compute((int)$request->user_id, (string)$request->user_role, 'list', null, $limit);
        return json(['code' => 200, 'msg' => 'ok', 'data' => $result]);
    }

    public function activityDetailRecommendations(Request $request, int $id)
    {
        $limit  = min(50, max(1, (int)$request->get('page_size', 6)));
        $result = $this->engine->compute((int)$request->user_id, (string)$request->user_role, 'detail_activity', $id, $limit, 'activity');
        return json(['code' => 200, 'msg' => 'ok', 'data' => $result]);
    }

    public function orderDetailRecommendations(Request $request, int $id)
    {
        $limit  = min(50, max(1, (int)$request->get('page_size', 6)));
        $result = $this->engine->compute((int)$request->user_id, (string)$request->user_role, 'detail_order', $id, $limit, 'order');
        return json(['code' => 200, 'msg' => 'ok', 'data' => $result]);
    }
}
