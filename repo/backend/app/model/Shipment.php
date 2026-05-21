<?php declare(strict_types=1);
namespace app\model;
use think\Model;
class Shipment extends Model {
    protected $name = 'shipments';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = false;
    public function packages() { return $this->hasMany(ShipmentPackage::class, 'shipment_id'); }
    public function events()   { return $this->hasMany(ShipmentEvent::class, 'shipment_id')->order('occurred_at', 'asc'); }
    public function getCreatedAtAttr($val): string { return $val ? date('m/d/Y g:i A', strtotime($val)) : ''; }
}
