<?php

use App\Http\Controllers\FlowLifecycleController;
use App\Http\Controllers\FrontendRedirectController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/privacy', [PublicPageController::class, 'privacy'])->name('privacy');
Route::get('/support', [PublicPageController::class, 'support'])->name('support');

// All webhook topics (incl. mandatory compliance topics), HMAC-verified. See shopify.app.toml.
Route::post('/webhooks', WebhookController::class)->middleware('shopify.webhook')->name('webhooks');

// Shopify Flow lifecycle callback (extensions/flow-lifecycle), same HMAC as webhooks.
Route::post('/flow/lifecycle', FlowLifecycleController::class)->middleware('shopify.webhook')->name('flow.lifecycle');

// The app's pages live on the frontend's own domain (app/frontend/). Anything else that reaches the
// backend in a browser (an old application_url, /auth/callback from a legacy link) is sent there.
Route::get('/{path?}', FrontendRedirectController::class)
    ->where('path', '^(?!api/|horizon|up$).*')
    ->name('app');
