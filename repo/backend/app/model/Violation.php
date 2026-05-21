<?php declare(strict_types=1);
namespace app\model;
use think\Model;
class Violation extends Model {
    protected $name = 'violations';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = false;
    public function rule()    { return $this->belongsTo(ViolationRule::class, 'rule_id'); }
    public function subject() { return $this->belongsTo(User::class, 'subject_user_id')->field(['id','username']); }
    public function evidence(){ return $this->hasMany(ViolationEvidence::class, 'violation_id'); }
    public function appeal()  { return $this->hasOne(ViolationAppeal::class, 'violation_id'); }
}
