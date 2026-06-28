<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\UserController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/menus', [MenuController::class, 'index']);
    Route::get('/menus/all', [MenuController::class, 'all']);
    Route::post('/menus', [MenuController::class, 'store']);
    Route::put('/menus/{menu}', [MenuController::class, 'update']);
    Route::delete('/menus/{menu}', [MenuController::class, 'destroy']);
    Route::get('/categories', [MenuController::class, 'categories']);

    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders/cleanup-test', [OrderController::class, 'cleanupTest']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders/{order}/void-request', [OrderController::class, 'requestVoid']);

    Route::get('/void-requests', [OrderController::class, 'voidRequests']);
    Route::post('/orders/{order}/void-approve', [OrderController::class, 'approveVoid']);
    Route::post('/orders/{order}/void-reject', [OrderController::class, 'rejectVoid']);

    Route::get('/reports/daily', [ReportController::class, 'daily']);
    Route::get('/reports/weekly', [ReportController::class, 'weekly']);
    Route::get('/reports/monthly', [ReportController::class, 'monthly']);

    Route::get('/audit-logs', [AuditLogController::class, 'index']);

    Route::get('/branches', [BranchController::class, 'index']);
    Route::post('/branches', [BranchController::class, 'store']);
    Route::put('/branches/{branch}', [BranchController::class, 'update']);
    Route::delete('/branches/{branch}', [BranchController::class, 'destroy']);

    // Kelola User — owner mengelola akun kasir. Tanpa DELETE: "hapus" = nonaktifkan
    // (is_active=false) karena orders.user_id cascade akan menghapus transaksi.
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{user}', [UserController::class, 'update']);
});
