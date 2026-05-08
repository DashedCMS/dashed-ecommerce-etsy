<?php

use Dashed\DashedEcommerceEtsy\Controllers\EtsyOAuthCallbackController;
use Dashed\DashedEcommerceEtsy\Controllers\EtsyOAuthStartController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->get('/dashed/etsy/oauth/start/{siteId}', EtsyOAuthStartController::class)
    ->name('dashed.etsy.oauth.start');

Route::middleware(['web', 'auth'])
    ->get('/dashed/etsy/oauth/callback', EtsyOAuthCallbackController::class)
    ->name('dashed.etsy.oauth.callback');
