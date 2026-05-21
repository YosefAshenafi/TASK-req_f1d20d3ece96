<?php declare(strict_types=1);
namespace app\model;
use think\Model;
class UserPointCache extends Model {
    protected $name = 'user_point_cache';
    protected $createTime = false;
    protected $dateFormat = false;
}
