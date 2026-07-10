<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SlipController;
use App\Http\Controllers\StudentLedgerController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::view('/students/search', 'students.search')->name('students.search');

    Route::get('/ledger/{enrollment}', [StudentLedgerController::class, 'show'])->name('ledger.show');
    Route::post('/ledger/{enrollment}/promissory', [StudentLedgerController::class, 'storePromissory'])->name('promissory.store');
    Route::post('/promissory/{promissoryNote}/status', [StudentLedgerController::class, 'updatePromissoryStatus'])->name('promissory.status');
    Route::post('/payments/{payment}/void', [StudentLedgerController::class, 'voidPayment'])->name('payments.void');
    Route::get('/slips/{payment}', [SlipController::class, 'show'])->name('slips.show');

    // Placeholders replaced by real report controllers in Task 12.
    Route::get('/reports/statement/{enrollment}', fn () => abort(501))->name('reports.statement');
    Route::get('/reports/notice/{enrollment}', fn () => abort(501))->name('reports.notice');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
