<?php declare(strict_types=1);
namespace app\model;
use think\Model;
class ViolationAppeal extends Model {
    protected $name = 'violation_appeals';
    protected $updateTime = false;
    protected $createTime = 'created_at';
    protected $dateFormat = false;
}
