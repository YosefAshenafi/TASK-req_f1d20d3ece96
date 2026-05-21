<?php
declare(strict_types=1);
namespace app\validate;

use think\Validate;

class ActivityValidate extends Validate
{
    protected $rule = [
        'title'          => 'require|max:512',
        'body'           => 'require',
        'max_headcount'  => 'integer|min:1',
        'signup_open_at' => 'date',
        'signup_close_at'=> 'date',
        'status'         => 'in:draft,published,in_progress,completed,archived',
    ];

    protected $scene = [
        'create'     => ['title', 'body', 'max_headcount', 'signup_open_at', 'signup_close_at'],
        'update'     => ['title', 'max_headcount', 'signup_open_at', 'signup_close_at'],
        'transition' => ['status'],
    ];
}
