<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChannelController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\StatementImportController;
use App\Http\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

// Public auth endpoints
Route::prefix('auth')->middleware('throttle:10,1')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::put('profile', [AuthController::class, 'updateProfile']);
        Route::put('password', [AuthController::class, 'updatePassword']);
        Route::post('tour', [AuthController::class, 'completeTour']);
    });

    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('counts', [DashboardController::class, 'counts']);
    Route::get('meta', [DashboardController::class, 'meta']);

    Route::get('orders/export', [OrderController::class, 'export']);
    Route::get('orders', [OrderController::class, 'index']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::get('orders/{id}', [OrderController::class, 'show'])->whereNumber('id');
    Route::patch('orders/{id}/status', [OrderController::class, 'updateStatus'])->whereNumber('id');
    Route::post('orders/{id}/refund', [OrderController::class, 'refund'])->whereNumber('id');

    Route::get('products/export', [ProductController::class, 'export']);
    Route::post('products/adjust', [ProductController::class, 'adjust']);
    Route::get('products/import-template', [ProductController::class, 'importTemplate']);
    Route::post('products/import', [ProductController::class, 'import']);
    Route::get('products', [ProductController::class, 'index']);
    Route::post('products', [ProductController::class, 'store']);
    Route::get('products/{id}', [ProductController::class, 'show'])->whereNumber('id');
    Route::put('products/{id}', [ProductController::class, 'update'])->whereNumber('id');
    Route::delete('products/{id}', [ProductController::class, 'destroy'])->whereNumber('id');

    Route::get('channels/logs', [ChannelController::class, 'logs']);
    Route::get('channels', [ChannelController::class, 'index']);
    Route::post('channels', [ChannelController::class, 'store']);
    Route::patch('channels/{id}', [ChannelController::class, 'update'])->whereNumber('id');
    Route::post('channels/{id}/sync', [ChannelController::class, 'sync'])->whereNumber('id');
    Route::post('channels/{id}/reauthorize', [ChannelController::class, 'reauthorize'])->whereNumber('id');
    Route::post('channels/{id}/disconnect', [ChannelController::class, 'disconnect'])->whereNumber('id');
    Route::post('channels/{id}/statements', [StatementImportController::class, 'store'])->whereNumber('id');

    Route::prefix('payments')->group(function () {
        Route::get('transactions', [PaymentController::class, 'transactions']);
        Route::post('transactions', [PaymentController::class, 'storeTransaction']);
        Route::delete('transactions/{id}', [PaymentController::class, 'destroyTransaction'])->whereNumber('id');
        Route::get('payouts', [PaymentController::class, 'payouts']);
        Route::post('payouts', [PaymentController::class, 'storePayout']);
        Route::patch('payouts/{id}', [PaymentController::class, 'updatePayout'])->whereNumber('id');
        Route::delete('payouts/{id}', [PaymentController::class, 'destroyPayout'])->whereNumber('id');
    });

    Route::get('statement-imports/template', [StatementImportController::class, 'template']);
    Route::get('statement-imports', [StatementImportController::class, 'index']);
    Route::delete('statement-imports/{id}', [StatementImportController::class, 'destroy'])->whereNumber('id');

    Route::prefix('finance')->group(function () {
        Route::get('profit-loss', [FinanceController::class, 'profitLoss']);
        Route::get('profit-loss/export', [FinanceController::class, 'exportProfitLoss']);
        Route::get('cash-flow', [FinanceController::class, 'cashFlow']);
        Route::get('cash-flow/export', [FinanceController::class, 'exportCashFlow']);
        Route::get('balance-sheet', [FinanceController::class, 'balanceSheet']);
        Route::get('accounts', [FinanceController::class, 'accounts']);
        Route::get('accounts/{id}/entries', [FinanceController::class, 'accountEntries'])->whereNumber('id');
        Route::get('journal-entries', [FinanceController::class, 'journalIndex']);
        Route::post('journal-entries', [FinanceController::class, 'journalStore']);
    });

    Route::prefix('purchasing')->group(function () {
        Route::get('suppliers', [SupplierController::class, 'index']);
        Route::post('suppliers', [SupplierController::class, 'store']);
        Route::put('suppliers/{id}', [SupplierController::class, 'update'])->whereNumber('id');
        Route::delete('suppliers/{id}', [SupplierController::class, 'destroy'])->whereNumber('id');

        Route::get('orders', [PurchaseOrderController::class, 'index']);
        Route::post('orders', [PurchaseOrderController::class, 'store']);
        Route::get('orders/{id}', [PurchaseOrderController::class, 'show'])->whereNumber('id');
        Route::put('orders/{id}', [PurchaseOrderController::class, 'update'])->whereNumber('id');
        Route::delete('orders/{id}', [PurchaseOrderController::class, 'destroy'])->whereNumber('id');
        Route::post('orders/{id}/order', [PurchaseOrderController::class, 'order'])->whereNumber('id');
        Route::post('orders/{id}/receive', [PurchaseOrderController::class, 'receive'])->whereNumber('id');
        Route::post('orders/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->whereNumber('id');
        Route::post('orders/{id}/payments', [PurchaseOrderController::class, 'storePayment'])->whereNumber('id');
        Route::delete('orders/{id}/payments/{paymentId}', [PurchaseOrderController::class, 'destroyPayment'])->whereNumber('id')->whereNumber('paymentId');
    });

    Route::get('expenses', [ExpenseController::class, 'index']);
    Route::post('expenses', [ExpenseController::class, 'store']);
    Route::delete('expenses/{id}', [ExpenseController::class, 'destroy'])->whereNumber('id');
});
