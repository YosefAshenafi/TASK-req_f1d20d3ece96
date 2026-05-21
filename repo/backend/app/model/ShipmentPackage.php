<?php declare(strict_types=1);
namespace app\model;
use think\Model;
class ShipmentPackage extends Model {
    protected $name = 'shipment_packages';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = false;
}
