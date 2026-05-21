<?php
return [
    // Global middleware — applied to every request
    \think\middleware\LoadLangPack::class,
    \app\middleware\Cors::class,
];
