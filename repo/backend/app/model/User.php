<?php
declare(strict_types=1);
namespace app\model;

use think\Model;

class User extends Model
{
    protected $name     = 'users';
    protected $hidden   = ['password_hash'];
    protected $readonly = ['password_hash'];

    // Expose safe fields only
    protected $visible = ['id', 'username', 'role', 'created_at', 'updated_at'];

    // ThinkPHP will auto-handle created_at / updated_at
    protected $autoWriteTimestamp = true;
    protected $dateFormat = false; // We format manually in service layer

    public function formatTimestamp(string $field): string
    {
        $val = $this->getData($field);
        if (!$val) return '';
        return date('m/d/Y g:i A', is_int($val) ? $val : strtotime($val));
    }

    public function verifyPassword(string $plain): bool
    {
        return password_verify($plain, (string)$this->getData('password_hash'));
    }
}
