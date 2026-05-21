<?php
declare(strict_types=1);
namespace app\model;

use think\Model;

class UserTag extends Model
{
    protected $name       = 'user_tags';
    protected $updateTime = false;
}
