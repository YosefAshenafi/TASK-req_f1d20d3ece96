<?php declare(strict_types=1);
namespace app\model;
use think\Model;
class InvoiceCorrection extends Model {
    protected $name = 'invoice_corrections';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = false;
    protected $json = ['field_patch'];
    protected $jsonAssoc = true;
}
