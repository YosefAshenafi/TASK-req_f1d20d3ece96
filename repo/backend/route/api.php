<?php
use think\facade\Route;
use app\middleware\Auth;
use app\middleware\RequireRole;

// Public routes — no auth required
Route::post('api/auth/login', 'Auth/login');

// Authenticated routes
Route::group('api', function () {

    Route::post('auth/logout', 'Auth/logout');

    // User management
    Route::group('users', function () {
        Route::get('', 'User/index');
        Route::post('', 'User/create');
        Route::get(':id', 'User/show');
        Route::put(':id', 'User/update');
        Route::delete(':id', 'User/destroy');
    });

    // Activities
    Route::group('activities', function () {
        Route::get('', 'Activity/index');
        Route::post('', 'Activity/create');
        Route::get(':id', 'Activity/show');
        Route::put(':id', 'Activity/update');
        Route::patch(':id/state', 'Activity/transition');
        Route::get(':id/versions', 'Activity/versions');
        Route::post(':id/signups', 'Activity/signup');
        Route::delete(':id/signups/:uid', 'Activity/cancelSignup');
        Route::get(':id/tasks', 'Task/index');
        Route::post(':id/tasks', 'Task/store');
        Route::put(':id/tasks/:tid', 'Task/update');
        Route::delete(':id/tasks/:tid', 'Task/destroy');
    });

    // Orders
    Route::group('orders', function () {
        Route::get('', 'Order/index');
        Route::post('', 'Order/create');
        Route::get(':id', 'Order/show');
        Route::patch(':id/state', 'Order/transition');
        Route::post(':id/refund', 'Order/refund');
        Route::post(':id/invoice-corrections', 'Order/requestCorrection');
    });

    Route::patch('invoice-corrections/:id/review', 'Order/reviewCorrection');

    // Fulfillment / Shipments
    Route::group('shipments', function () {
        Route::get('', 'Fulfillment/index');
        Route::post('', 'Fulfillment/create');
        Route::get(':id', 'Fulfillment/show');
        Route::post(':id/events', 'Fulfillment/addEvent');
        Route::patch(':id/deliver', 'Fulfillment/confirmDelivery');
        Route::post(':id/exceptions', 'Fulfillment/recordException');
    });

    Route::get('subscriptions', 'Fulfillment/getSubscription');
    Route::put('subscriptions', 'Fulfillment/updateSubscription');

    // Violations
    Route::group('violation-rules', function () {
        Route::get('', 'Violation/listRules');
        Route::post('', 'Violation/createRule');
        Route::put(':id', 'Violation/updateRule');
        Route::delete(':id', 'Violation/deleteRule');
    });

    Route::group('violations', function () {
        Route::get('', 'Violation/index');
        Route::post('', 'Violation/create');
        Route::get(':id', 'Violation/show');
        Route::post(':id/evidence', 'Violation/attachEvidence');
        Route::post(':id/appeals', 'Violation/appeal');
        Route::patch(':id/appeals/review', 'Violation/reviewAppeal');
    });

    Route::get('point-summary/users/:uid', 'Violation/userPointSummary');
    Route::get('point-summary/groups/:gid', 'Violation/groupPointSummary');

    // Search
    Route::get('search', 'Search/globalSearch');
    Route::get('search/logistics', 'Search/logisticsSearch');

    // Recommendations
    Route::get('recommendations', 'Recommendation/listRecommendations');
    Route::get('recommendations/activities/:id', 'Recommendation/activityDetailRecommendations');

    // Dashboards
    Route::group('dashboards', function () {
        Route::get('', 'Dashboard/index');
        Route::post('', 'Dashboard/create');
        Route::get(':id', 'Dashboard/show');
        Route::put(':id', 'Dashboard/update');
        Route::delete(':id', 'Dashboard/destroy');
        Route::post(':id/favorite', 'Dashboard/favorite');
        Route::delete(':id/favorite', 'Dashboard/unfavorite');
        Route::post(':id/export', 'Dashboard/export');
    });

    Route::get('widgets/data', 'Dashboard/widgetData');

    // Sensitive field access (admin only)
    Route::get('users/:id/sensitive', 'Dashboard/sensitiveFields');

})->middleware(Auth::class);
