<?php

use App\Http\Controllers\FeatureController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\ShopController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:20,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/', OverviewController::class)->name('overview');
    Route::post('/sync', [OverviewController::class, 'sync'])->middleware('throttle:6,1')->name('sync');

    Route::get('/shops', [ShopController::class, 'index'])->name('shops.index');
    Route::get('/shops/export', [ShopController::class, 'export'])->name('shops.export');
    Route::get('/shops/{shop}', [ShopController::class, 'show'])->whereNumber('shop')->name('shops.show');

    Route::get('/features', FeatureController::class)->name('features');
    Route::get('/health', HealthController::class)->name('health');
});
