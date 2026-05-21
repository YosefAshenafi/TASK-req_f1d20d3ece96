<?php declare(strict_types=1);
namespace app\model;
use think\Model;
class Order extends Model {
    protected $name = 'orders';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = false;
    public function getCreatedAtAttr($val): string { return $val ? date('m/d/Y g:i A', strtotime($val)) : ''; }
    public function getUpdatedAtAttr($val): string { return $val ? date('m/d/Y g:i A', strtotime($val)) : ''; }
    public function shipments() { return $this->hasMany(Shipment::class, 'order_id'); }
    public function creator()   { return $this->belongsTo(User::class, 'created_by')->field(['id','username']); }
}
