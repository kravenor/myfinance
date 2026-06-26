<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategorizationRuleController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ExchangeRateController;
use App\Http\Controllers\InvestmentController;
use App\Http\Controllers\InvestmentHoldingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\RecurringTransactionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SavingsGoalController;
use App\Http\Controllers\ScenarioController;
use App\Http\Controllers\ScenarioItemController;
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

    Route::get('notification-preferences', [NotificationPreferenceController::class, 'show'])->name('notification-preferences.show');
    Route::put('notification-preferences', [NotificationPreferenceController::class, 'update'])->name('notification-preferences.update');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::delete('notifications/{id}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

    Route::get('investments/overview', [InvestmentController::class, 'overview'])->name('investments.overview');
    Route::apiResource('investment-holdings', InvestmentHoldingController::class)
        ->parameter('investment-holdings', 'investment_holding');

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

    Route::apiResource('scenarios', ScenarioController::class);
    Route::apiResource('scenarios.items', ScenarioItemController::class)
        ->parameter('items', 'item')
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
        Route::get('expense-forecast/compare', 'expenseForecastCompare')->name('reports.expense-forecast.compare');
        Route::get('expense-forecast', 'expenseForecast')->name('reports.expense-forecast');
    });
});
