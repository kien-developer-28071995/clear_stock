<?php

use App\Http\Controllers\EmbeddedAppController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/privacy', [PublicPageController::class, 'privacy'])->name('privacy');
Route::get('/support', [PublicPageController::class, 'support'])->name('support');

// All webhook topics (incl. mandatory compliance topics), HMAC-verified. See shopify.app.toml.
Route::post('/webhooks', WebhookController::class)->middleware('shopify.webhook')->name('webhooks');

// Listed in shopify.app.toml [auth] redirect_urls; with Shopify-managed install it is
// only hit by legacy links, so send the merchant into the embedded app.
Route::get('/auth/callback', EmbeddedAppController::class);

// Embedded SPA: every other GET path renders the React shell (client-side routing).
Route::get('/{path?}', EmbeddedAppController::class)
    ->where('path', '^(?!api/|horizon|up$|build/).*')
    ->middleware('embedded.headers')
    ->name('app');
