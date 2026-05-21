<?php declare(strict_types=1);
namespace app\model;
use think\Model;
class UserSubscription extends Model {
    protected $name = 'user_subscriptions';
    protected $createTime = false;
    protected $dateFormat = false;
}
