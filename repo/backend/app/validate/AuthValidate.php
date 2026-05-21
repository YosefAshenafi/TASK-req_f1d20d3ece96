<?php
declare(strict_types=1);
namespace app\validate;

use think\Validate;

class AuthValidate extends Validate
{
    protected $rule = [
        'username' => 'require|alphaDash|min:3|max:64',
        'password' => 'require|min:10|max:128',
    ];

    protected $message = [
        'username.require'   => 'Username is required',
        'username.alphaDash' => 'Username must contain only letters, numbers, hyphens, or underscores',
        'username.min'       => 'Username must be at least 3 characters',
        'password.require'   => 'Password is required',
        'password.min'       => 'Password must be at least 10 characters',
        'password.max'       => 'Password must be at most 128 characters',
    ];
}
