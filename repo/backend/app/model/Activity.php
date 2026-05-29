<?php
declare(strict_types=1);
namespace app\model;

use think\Model;

class Activity extends Model
{
    protected $name               = 'activities';
    protected $autoWriteTimestamp = true;
    protected $dateFormat         = false;
    protected $json               = ['required_supplies'];
    protected $jsonAssoc          = true;

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id')->field(['id', 'username', 'role']);
    }

    public function versions()
    {
        return $this->hasMany(ActivityVersion::class, 'activity_id')->order('version_number', 'desc');
    }

    public function tags()
    {
        return $this->hasMany(ActivityTag::class, 'activity_id');
    }

    public function signups()
    {
        return $this->hasMany(ActivitySignup::class, 'activity_id')->where('status', 'active');
    }

    public function getCreatedAtAttr($val): string
    {
        return $val ? date('m/d/Y g:i A', strtotime($val)) : '';
    }

    public function getUpdatedAtAttr($val): string
    {
        return $val ? date('m/d/Y g:i A', strtotime($val)) : '';
    }

    public function getPublishedAtAttr($val): string
    {
        return $val ? date('m/d/Y g:i A', strtotime($val)) : '';
    }

    public function getInProgressAtAttr($val): string
    {
        return $val ? date('m/d/Y g:i A', strtotime($val)) : '';
    }

    public function getCompletedAtAttr($val): string
    {
        return $val ? date('m/d/Y g:i A', strtotime($val)) : '';
    }

    public function getArchivedAtAttr($val): string
    {
        return $val ? date('m/d/Y g:i A', strtotime($val)) : '';
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeVisible($query, string $role)
    {
        if ($role === 'regular') {
            return $query->where('status', 'published');
        }
        return $query;
    }
}
