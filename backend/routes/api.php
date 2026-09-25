<?php

use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

// All API routes are called by the embedded app with an App Bridge session token.
Route::middleware('shopify.session')->group(function () {
    Route::get('/shop', [ShopController::class, 'show']);
    Route::get('/sync', [SyncController::class, 'show']);
    Route::post('/sync', [SyncController::class, 'store'])->middleware('throttle:10,1');
});
