<?php


use App\Http\Controllers\Gateway\DigitoPayController;
use Illuminate\Support\Facades\Route;

Route::prefix('digitopay')
    ->group(function ()
    {
        Route::post('callback', [DigitoPayController::class, 'callbackMethod']);
    });

