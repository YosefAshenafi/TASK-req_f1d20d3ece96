<?php
declare(strict_types=1);

namespace app\validate;

use think\Validate;

class DashboardValidate extends Validate
{
    protected $rule = [
        'name'        => 'max:120',
        'layout_json' => 'require|array',
        'format'      => 'require|in:png,pdf,xlsx',
        'widget_type' => 'require|in:activity_status,order_pipeline,violation_leaderboard,fulfillment_rate',
    ];

    protected $message = [
        'name.max'          => 'Dashboard name may not exceed 120 characters.',
        'layout_json.require' => 'layout_json is required.',
        'layout_json.array'   => 'layout_json must be a JSON array.',
        'format.require'    => 'Export format is required.',
        'format.in'         => 'Format must be one of: png, pdf, xlsx.',
        'widget_type.require'=> 'widget_type is required.',
        'widget_type.in'    => 'widget_type must be one of: activity_status, order_pipeline, violation_leaderboard, fulfillment_rate.',
    ];

    protected $scene = [
        'create' => ['name', 'layout_json'],
        'update' => ['name', 'layout_json'],
        'export' => ['format'],
        'widget' => ['widget_type'],
    ];
}
