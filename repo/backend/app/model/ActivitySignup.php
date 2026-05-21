<?php
declare(strict_types=1);
namespace app\model;

use think\Model;

class ActivitySignup extends Model
{
    protected $name       = 'activity_signups';
    protected $updateTime = false;

    public function getSignedUpAtAttr($val): string
    {
        return $val ? date('m/d/Y g:i A', strtotime($val)) : '';
    }
}
