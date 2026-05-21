<?php declare(strict_types=1);
namespace app\model;
use think\Model;
class ShipmentEvent extends Model {
    protected $name = 'shipment_events';
    protected $updateTime = false;
    protected $dateFormat = false;
    public function getOccurredAtAttr($val): string { return $val ? date('m/d/Y g:i A', strtotime($val)) : ''; }
}
