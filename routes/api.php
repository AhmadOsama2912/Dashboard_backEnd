<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminUserController;          // System Admins
use App\Http\Controllers\Admin\AdminPackageController;       // Packages
use App\Http\Controllers\Admin\AdminCustomerController;      // Customers
use App\Http\Controllers\Admin\AdminCustomerUserController;  // Tenant Users (manager/supervisor)
use App\Http\Controllers\User\UserTokenController;

Route::prefix('admin/v1')->middleware('force.json')->group(function () {
    // --- Auth (token) ---
    Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AdminAuthController::class, 'me']);
        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::post('/logout-all', [AdminAuthController::class, 'logoutAll']);

        // --- Management (requires admin:manage) ---
        Route::middleware('abilities:admin:manage')->group(function () {

            /* ===== System Admins ===== */
            Route::get('/admins', [AdminUserController::class, 'index'])->middleware('throttle:120,1');
            Route::get('/admins/{admin}', [AdminUserController::class, 'show'])
                ->whereNumber('admin')->middleware('throttle:120,1');
            Route::post('/admins', [AdminUserController::class, 'store'])->middleware('throttle:30,1');
            Route::patch('/admins/{admin}', [AdminUserController::class, 'update'])
                ->whereNumber('admin')->middleware('throttle:30,1');
            Route::delete('/admins/{admin}', [AdminUserController::class, 'destroy'])
                ->whereNumber('admin')->middleware('throttle:30,1');

            /* ===== Packages ===== */
            Route::get('/packages', [AdminPackageController::class, 'index'])->middleware('throttle:120,1');
            Route::get('/packages/{package}', [AdminPackageController::class, 'show'])
                ->whereNumber('package')->middleware('throttle:120,1');
            Route::post('/packages', [AdminPackageController::class, 'store'])->middleware('throttle:30,1');
            Route::patch('/packages/{package}', [AdminPackageController::class, 'update'])
                ->whereNumber('package')->middleware('throttle:30,1');
            Route::delete('/packages/{package}', [AdminPackageController::class, 'destroy'])
                ->whereNumber('package')->middleware('throttle:30,1');

            /* ===== Customers ===== */
            Route::get('/customers', [AdminCustomerController::class, 'index'])->middleware('throttle:120,1');
            Route::get('/customers/{customer}', [AdminCustomerController::class, 'show'])
                ->whereNumber('customer')->middleware('throttle:120,1');
            Route::post('/customers', [AdminCustomerController::class, 'store'])->middleware('throttle:30,1');
            Route::patch('/customers/{customer}', [AdminCustomerController::class, 'update'])
                ->whereNumber('customer')->middleware('throttle:30,1');
            Route::delete('/customers/{customer}', [AdminCustomerController::class, 'destroy'])
                ->whereNumber('customer')->middleware('throttle:30,1');

            /* ===== Tenant Users (Manager/Supervisor) ===== */
            Route::get('/users', [AdminCustomerUserController::class, 'index'])->middleware('throttle:120,1');
            Route::get('/users/{user}', [AdminCustomerUserController::class, 'show'])
                ->whereNumber('user')->middleware('throttle:120,1');
            Route::post('/users', [AdminCustomerUserController::class, 'store'])->middleware('throttle:30,1');
            Route::patch('/users/{user}', [AdminCustomerUserController::class, 'update'])
                ->whereNumber('user')->middleware('throttle:30,1');
            Route::delete('/users/{user}', [AdminCustomerUserController::class, 'destroy'])
                ->whereNumber('user')->middleware('throttle:30,1');
        });
    });
});

Route::prefix('user/v1')->middleware('force.json')->group(function () {
    Route::post('/login', [UserTokenController::class, 'login'])->middleware('throttle:20,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [UserTokenController::class, 'me']);
        Route::post('/logout', [UserTokenController::class, 'logout']);
        Route::post('/logout-all', [UserTokenController::class, 'logoutAll']);
    });
});
