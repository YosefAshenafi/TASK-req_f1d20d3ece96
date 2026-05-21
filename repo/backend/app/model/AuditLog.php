<?php
declare(strict_types=1);
namespace app\model;

use think\Model;

class AuditLog extends Model
{
    protected $name              = 'audit_log';
    protected $autoWriteTimestamp = 'datetime';
    protected $updateTime        = false;
    protected $json              = ['payload'];
    protected $jsonAssoc         = true;

    public static function record(int $userId, string $action, string $entityType, int $entityId, array $payload = []): void
    {
        self::create([
            'user_id'     => $userId,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'payload'     => $payload,
        ]);
    }
}
