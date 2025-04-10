<?php


use App\Http\Controllers\Gateway\SuitPayController;
use Illuminate\Support\Facades\Route;


Route::prefix('suitpay')
    ->group(function ()
    {
        Route::get('withdrawal/{id}/{action}', [SuitPayController::class, 'withdrawalFromModal'])->name('suitpay.withdrawal');
        Route::get('cancelwithdrawal/{id}/{action}', [SuitPayController::class, 'cancelWithdrawalFromModal'])->name('suitpay.cancelwithdrawal');
        Route::post('callback', [SuitPayController::class, 'callbackMethod']);
        Route::post('payment', [SuitPayController::class, 'callbackMethodPayment']);
        
    });
