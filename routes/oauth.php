<?php

use Illuminate\Support\Facades\Route;
use Dashed\DashedEcommerceEtsy\Controllers\EtsyOAuthStartController;
use Dashed\DashedEcommerceEtsy\Controllers\EtsyOAuthCallbackController;

Route::middleware(['web', 'auth'])
    ->get('/dashed/etsy/oauth/start/{siteId}', EtsyOAuthStartController::class)
    ->name('dashed.etsy.oauth.start');

Route::middleware(['web', 'auth'])
    ->get('/dashed/etsy/oauth/callback', EtsyOAuthCallbackController::class)
    ->name('dashed.etsy.oauth.callback');
