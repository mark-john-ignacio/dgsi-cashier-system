<?php

use App\Http\Controllers\PrintController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app');

Route::middleware('auth')->prefix('print')->name('print.')->group(function () {
    Route::get('/slip/{payment}', [PrintController::class, 'slip'])->name('slip');
    Route::get('/notice/{enrollment}', [PrintController::class, 'notice'])->name('notice');
    Route::get('/statement/{enrollment}', [PrintController::class, 'statement'])->name('statement');
    Route::get('/notices', [PrintController::class, 'batchNotices'])->name('notices.batch');
});
