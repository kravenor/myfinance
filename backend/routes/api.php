<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategorizationRuleController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ExchangeRateController;
use App\Http\Controllers\RecurringTransactionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SavingsGoalController;
use App\Http\Controllers\SavingsGoalMovementController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionImportExportController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register'])->name('auth.register');
Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.email');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

    Route::get('exchange-rates', [ExchangeRateController::class, 'index'])->name('exchange-rates.index');
    Route::get('exchange-rates/convert', [ExchangeRateController::class, 'convert'])->name('exchange-rates.convert');

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
    Route::get('budgets/alerts', [BudgetController::class, 'alerts'])->name('budgets.alerts');
    Route::apiResource('budgets', BudgetController::class);
    Route::apiResource('recurring-transactions', RecurringTransactionController::class)
        ->parameter('recurring-transactions', 'recurring_transaction');
    Route::post('categorization-rules/apply', [CategorizationRuleController::class, 'apply'])
        ->name('categorization-rules.apply');
    Route::apiResource('categorization-rules', CategorizationRuleController::class)
        ->parameter('categorization-rules', 'categorization_rule');
    Route::post('transactions/import/preview-predictions', [TransactionImportExportController::class, 'importPreviewPredictions'])
        ->name('transactions.import.preview-predictions');

    Route::apiResource('savings-goals', SavingsGoalController::class)
        ->parameter('savings-goals', 'savings_goal');
    Route::apiResource('savings-goals.movements', SavingsGoalMovementController::class)
        ->parameter('savings-goals', 'savings_goal')
        ->parameter('movements', 'movement')
        ->scoped();

    Route::prefix('reports')->controller(ReportController::class)->group(function () {
        Route::get('summary', 'summary')->name('reports.summary');
        Route::get('by-category', 'byCategory')->name('reports.by-category');
        Route::get('by-tag', 'byTag')->name('reports.by-tag');
        Route::get('timeline', 'timeline')->name('reports.timeline');
        Route::get('net-worth', 'netWorth')->name('reports.net-worth');
        Route::get('period-comparison', 'periodComparison')->name('reports.period-comparison');
        Route::get('category-trend', 'categoryTrend')->name('reports.category-trend');
        Route::get('top-transactions', 'topTransactions')->name('reports.top-transactions');
        Route::get('cash-flow-forecast', 'cashFlowForecast')->name('reports.cash-flow-forecast');
    });
});
