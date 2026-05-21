<?php declare(strict_types=1);
namespace app\model;
use think\Model;
class ViolationRule extends Model {
    protected $name = 'violation_rules';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = false;
}
