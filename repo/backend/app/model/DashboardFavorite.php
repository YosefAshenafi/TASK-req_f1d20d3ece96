<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class DashboardFavorite extends Model
{
    protected $name       = 'dashboard_favorites';
    protected $pk         = 'id';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = false;
}
