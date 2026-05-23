<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\RecurringTransactionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionImportExportController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register'])->name('auth.register');
Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

    Route::apiResource('accounts', AccountController::class);
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('tags', TagController::class);
    Route::get('transactions/export', [TransactionImportExportController::class, 'export'])
        ->name('transactions.export');
    Route::post('transactions/import/preview', [TransactionImportExportController::class, 'importPreview'])
        ->name('transactions.import.preview');
    Route::post('transactions/import', [TransactionImportExportController::class, 'importCommit'])
        ->name('transactions.import.commit');
    Route::apiResource('transactions', TransactionController::class);
    Route::apiResource('budgets', BudgetController::class);
    Route::apiResource('recurring-transactions', RecurringTransactionController::class)
        ->parameter('recurring-transactions', 'recurring_transaction');

    Route::prefix('reports')->controller(ReportController::class)->group(function () {
        Route::get('summary', 'summary')->name('reports.summary');
        Route::get('by-category', 'byCategory')->name('reports.by-category');
        Route::get('timeline', 'timeline')->name('reports.timeline');
        Route::get('net-worth', 'netWorth')->name('reports.net-worth');
    });
});
