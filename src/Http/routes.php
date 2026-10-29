<?php

use App\Http\Middleware\VerifyCsrfToken as AppVerifyCsrfToken;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Vfixtechnology\Cashfree\Http\Controllers\CashfreeController;

Route::group(['middleware' => ['web']], function () {
    Route::get('cashfree/redirect', [CashfreeController::class, 'redirect'])->name('cashfree.redirect');

    Route::get('/cashfree/callback', [CashfreeController::class, 'verify'])->name('cashfree.success');

    Route::post('/cashfree/webhook', [CashfreeController::class, 'webhook'])
        ->withoutMiddleware([
            PreventRequestForgery::class,
            VerifyCsrfToken::class,
            AppVerifyCsrfToken::class,
        ])
        ->name('cashfree.webhook');
});
