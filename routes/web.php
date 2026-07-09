<?php

use ColorrageAR\Autoresponder\Http\Controllers\TrackingController;
use ColorrageAR\Autoresponder\Http\Controllers\UnsubscribeController;
use Illuminate\Support\Facades\Route;

Route::group([
    'prefix' => config('autoresponder.route_prefix', 'autoresponder'),
    'middleware' => config('autoresponder.route_middleware', ['web']),
], function () {
    Route::get('track/open/{id}', [TrackingController::class, 'trackOpen'])
        ->name('autoresponder.track.open');

    Route::get('track/click/{id}/{url}', [TrackingController::class, 'trackClick'])
        ->name('autoresponder.track.click')
        ->where('url', '.*');

    Route::get('unsubscribe/{token}', [UnsubscribeController::class, 'show'])
        ->name('autoresponder.unsubscribe');

    Route::post('unsubscribe/{token}', [UnsubscribeController::class, 'process'])
        ->name('autoresponder.unsubscribe.process');
});
