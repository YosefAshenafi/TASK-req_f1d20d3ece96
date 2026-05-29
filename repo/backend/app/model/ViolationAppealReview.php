<?php declare(strict_types=1);
namespace app\model;
use think\Model;
class ViolationAppealReview extends Model {
    protected $name        = 'violation_appeal_reviews';
    protected $updateTime  = false;
    protected $createTime  = 'created_at';
    protected $dateFormat  = false;
}
