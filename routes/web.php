<?php

use App\Models\Game;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Gateway\SuitPayController;

// Em routes/web.php ou routes/api.php
Route::post('/suitpay/consult-status-transaction', [SuitPayController::class, 'consultStatusTransactionPix']);


Route::get('clear', function() {
    Artisan::command('clear', function () {
        Artisan::call('optimize:clear');
       return back();
    });

    return back();
});


include_once(__DIR__ . '/groups/provider/playFiver.php');
include_once(__DIR__ . '/groups/gateways/suitpay.php');
include_once(__DIR__ . '/groups/auth/social.php');
include_once(__DIR__ . '/groups/layouts/app.php');

