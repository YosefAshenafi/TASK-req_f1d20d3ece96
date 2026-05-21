<?php
declare(strict_types=1);
namespace app\validate;

use think\Validate;

class UserValidate extends Validate
{
    protected $rule = [
        'username' => 'require|alphaDash|min:3|max:64',
        'password' => 'require|min:10|max:128',
        'role'     => 'require|in:admin,ops_staff,team_lead,reviewer,regular',
    ];

    protected $scene = [
        'create' => ['username', 'password', 'role'],
        'update' => ['role'],
    ];
}
