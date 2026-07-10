<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FeeStructureController;
use App\Http\Controllers\FeeTypeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SchoolYearController;
use App\Http\Controllers\SlipController;
use App\Http\Controllers\StudentController;
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

    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('/daily', [ReportController::class, 'daily'])->name('daily');
        Route::get('/unpaid', [ReportController::class, 'unpaid'])->name('unpaid');
        Route::get('/notices', [ReportController::class, 'batchNotices'])->name('notices.batch');
        Route::get('/notice/{enrollment}', [ReportController::class, 'notice'])->name('notice');
        Route::get('/statement/{enrollment}', [ReportController::class, 'statement'])->name('statement');
    });

    Route::resource('students', StudentController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    Route::post('students/{student}/register', [StudentController::class, 'register'])->name('students.register');

    Route::middleware('role:admin')->group(function () {
        Route::get('fee-types', [FeeTypeController::class, 'index'])->name('fee-types.index');
        Route::post('fee-types', [FeeTypeController::class, 'store'])->name('fee-types.store');
        Route::resource('fee-structures', FeeStructureController::class)->only(['index', 'create', 'store', 'edit', 'update']);
        Route::get('school-years', [SchoolYearController::class, 'index'])->name('school-years.index');
        Route::post('school-years', [SchoolYearController::class, 'store'])->name('school-years.store');
        Route::post('school-years/{schoolYear}/activate', [SchoolYearController::class, 'activate'])->name('school-years.activate');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
