<?php
declare(strict_types=1);
namespace app\model;

use think\Model;

class ActivityVersion extends Model
{
    protected $name      = 'activity_versions';
    protected $json      = ['snapshot', 'diff'];
    protected $jsonAssoc = true;
    protected $updateTime = false;

    public function getCreatedAtAttr($val): string
    {
        return $val ? date('m/d/Y g:i A', strtotime($val)) : '';
    }
}
