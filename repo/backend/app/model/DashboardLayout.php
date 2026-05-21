<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class DashboardLayout extends Model
{
    protected $name       = 'dashboard_layouts';
    protected $pk         = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    protected $json       = ['layout_json'];
    protected $jsonAssoc  = true;
}
