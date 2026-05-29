<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

class WidgetDataService
{
    /** Activity status counts across all non-archived activities. */
    public static function activityStatusCounts(): array
    {
        $rows = Db::table('activities')
            ->field('status, COUNT(*) as cnt')
            ->where('status', '<>', 'archived')
            ->group('status')
            ->select()
            ->toArray();

        $result = [];
        foreach ($rows as $r) {
            $result[$r['status']] = (int)$r['cnt'];
        }
        return $result;
    }

    /** Order pipeline counts by state. */
    public static function orderPipeline(): array
    {
        $rows = Db::table('orders')
            ->field('status, COUNT(*) as cnt')
            ->group('status')
            ->select()
            ->toArray();

        $result = [];
        foreach ($rows as $r) {
            $result[$r['status']] = (int)$r['cnt'];
        }
        return $result;
    }

    /** Top-10 users by total violation points (leaderboard). */
    public static function violationLeaderboard(): array
    {
        $rows = Db::table('user_point_cache as upc')
            ->join('users u', 'u.id = upc.user_id')
            ->field('u.username, upc.total_points')
            ->order('upc.total_points', 'desc')
            ->limit(10)
            ->select()
            ->toArray();

        return $rows;
    }

    /**
     * Fulfillment delivery rate: percentage of shipments whose latest event
     * is 'delivered' out of all shipments created in the last 30 days.
     */
    public static function fulfillmentRate(): array
    {
        $total = Db::table('shipments')
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')))
            ->count();

        if ($total === 0) {
            return ['total' => 0, 'delivered' => 0, 'rate_pct' => 0];
        }

        $delivered = Db::table('shipments s')
            ->join('shipment_events se', 'se.shipment_id = s.id')
            ->where('se.event_type', 'delivered')
            ->where('s.created_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')))
            ->count('DISTINCT s.id');

        return [
            'total'     => (int)$total,
            'delivered' => (int)$delivered,
            'rate_pct'  => round($delivered / $total * 100, 1),
        ];
    }

    /** Dispatch data for a named widget type. Optionally includes drill-down records. */
    public static function getWidgetData(string $widgetType, array $filters = []): array
    {
        $base = match ($widgetType) {
            'activity_status'       => self::activityStatusCounts(),
            'order_pipeline'        => self::orderPipeline(),
            'violation_leaderboard' => self::violationLeaderboard(),
            'fulfillment_rate'      => self::fulfillmentRate(),
            default                 => throw new \InvalidArgumentException("Unknown widget type: {$widgetType}"),
        };

        if (!empty($filters['drill_status'])) {
            $base['drill'] = self::drillRecords($widgetType, $filters['drill_status']);
        }

        return $base;
    }

    /**
     * Returns detailed records for a given widget type and status.
     * Used for drill-down interactions from widget summary rows.
     */
    private static function drillRecords(string $widgetType, string $status): array
    {
        if ($widgetType === 'activity_status') {
            return Db::table('activities')
                ->field('id, title, status, created_at')
                ->where('status', $status)
                ->order('created_at', 'desc')
                ->limit(20)
                ->select()
                ->toArray();
        }
        if ($widgetType === 'order_pipeline') {
            return Db::table('orders')
                ->field('id, type, status, created_at')
                ->where('status', $status)
                ->order('created_at', 'desc')
                ->limit(20)
                ->select()
                ->toArray();
        }
        return [];
    }
}
