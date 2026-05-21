<?php declare(strict_types=1);
namespace app\model;
use think\Model;
class ActivityTask extends Model {
    protected $name = 'activity_tasks';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = false;
    protected $json = ['checklist'];
    protected $jsonAssoc = true;
    public function getCreatedAtAttr($v): string { return $v ? date('m/d/Y g:i A', strtotime($v)) : ''; }
}
