<?php

use Illuminate\Support\Facades\Route;
use Vfixtechnology\Cashfree\Http\Controllers\CashfreeController;

Route::group(['middleware' => ['web']], function () {
    Route::get('cashfree/redirect', [CashfreeController::class, 'redirect'])->name('cashfree.redirect');
    Route::get('/cashfree/callback', [CashfreeController::class, 'verify'])->name('cashfree.success');

});
