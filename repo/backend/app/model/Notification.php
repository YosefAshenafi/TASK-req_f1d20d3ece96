<?php declare(strict_types=1);
namespace app\model;
use think\Model;
class Notification extends Model {
    protected $name = 'notifications';
    protected $updateTime = false;
    protected $dateFormat = false;
    public function getCreatedAtAttr($v): string { return $v ? date('m/d/Y g:i A', strtotime($v)) : ''; }
}
