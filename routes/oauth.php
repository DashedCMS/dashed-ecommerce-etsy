<?php

use Dashed\DashedEcommerceEtsy\Controllers\EtsyOAuthCallbackController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->get('/dashed/etsy/oauth/callback', EtsyOAuthCallbackController::class)
    ->name('dashed.etsy.oauth.callback');
