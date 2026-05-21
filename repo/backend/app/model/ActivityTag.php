<?php
declare(strict_types=1);
namespace app\model;

use think\Model;

class ActivityTag extends Model
{
    protected $name       = 'activity_tags';
    protected $updateTime = false;
    protected $createTime = false;
}
