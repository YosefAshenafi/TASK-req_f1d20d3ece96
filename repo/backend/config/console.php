<?php
return [
    'commands' => [
        'order:auto-cancel'          => \app\command\AutoCancelOrders::class,
        'index:cleanup'              => \app\command\CleanupIndex::class,
        'recommendation:recompute'   => \app\command\RecomputeRecommendations::class,
        'db:seed'                    => \app\command\SeedDatabase::class,
    ],
];
