<?php

use Illuminate\Support\Facades\Route;

/* Admin domain */
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminUserController;            // System admins (staff)
use App\Http\Controllers\Admin\AdminPackageController;         // Packages
use App\Http\Controllers\Admin\AdminCustomerController;        // Companies
use App\Http\Controllers\Admin\AdminCustomerUserController;    // Tenant users (manager/supervisor)
use App\Http\Controllers\Admin\AdminEnrollmentCodeController;  // Screen enrollment codes
use App\Http\Controllers\Admin\AdminPlaylistController;        // Playlists (global)
use App\Http\Controllers\Admin\AdminPlaylistItemController;    // Playlist items (global)
use App\Http\Controllers\Admin\AdminScreenContentController;   // Per-screen assign/refresh (global)
use App\Http\Controllers\Admin\AdminContentBulkController;     // Bulk content ops (global)
use App\Http\Controllers\Admin\AdminDashboardController;       // Admin dashboard APIs
use App\Http\Controllers\Admin\AdminScreenController;          // Screens (global)
use App\Http\Controllers\PlaylistPushController;         // WS bump helper (admin)

/* Tenant (manager/supervisor) */
use App\Http\Controllers\User\UserTokenController;             // Login for manager/supervisor
use App\Http\Controllers\User\UserScreenAssignController;      // Assign supervisor to screen (manager only)
use App\Http\Controllers\User\CompanyPlaylistController;       // Playlists (company scope, manager only)
use App\Http\Controllers\User\CompanyPlaylistItemController;   // Playlist items (company scope)
use App\Http\Controllers\User\TenantScreenContentController;   // Per-screen assign/refresh (scoped)
use App\Http\Controllers\User\TenantContentBulkController;     // Bulk content ops (scoped)
use App\Http\Controllers\User\UserDashboardController;         // Tenant dashboard APIs
use App\Http\Controllers\User\UserScreenController;            // Tenant screens
use App\Http\Controllers\User\UserSupervisorController;        // Tenant supervisor management


/* Device (screen) */
use App\Http\Controllers\Screen\ScreenController;

/* =================================================================== */
/*                           ADMIN API (v1)                            */
/* =================================================================== */
Route::prefix('admin/v1')->as('admin.v1.')->middleware('force.json')->group(function () {

    /* --- Auth (token based) ---------------------------------------- */
    Route::post('/login', [AdminAuthController::class, 'login'])
        ->name('auth.login')
        ->middleware('throttle:10,1');

    /* --- Protected (Sanctum) --------------------------------------- */
    Route::middleware('auth:sanctum')->group(function () {

        /* Self */
        Route::get('/me',        [AdminAuthController::class, 'me'])->name('auth.me');
        Route::post('/logout',   [AdminAuthController::class, 'logout'])->name('auth.logout');
        Route::post('/logout-all',[AdminAuthController::class, 'logoutAll'])->name('auth.logout_all');



            /* ========== System Admins (staff) ========== */
            Route::get('/admins',            [AdminUserController::class, 'index'])->name('admins.index')->middleware('throttle:120,1');
            Route::get('/admins/{admin}',    [AdminUserController::class, 'show'])->whereNumber('admin')->name('admins.show')->middleware('throttle:120,1');
            Route::post('/admins',           [AdminUserController::class, 'store'])->name('admins.store')->middleware('throttle:30,1');
            Route::patch('/admins/{admin}',  [AdminUserController::class, 'update'])->whereNumber('admin')->name('admins.update')->middleware('throttle:30,1');
            Route::delete('/admins/{admin}', [AdminUserController::class, 'destroy'])->whereNumber('admin')->name('admins.destroy')->middleware('throttle:30,1');

            /* ========== Packages ========== */
            Route::get('/packages',              [AdminPackageController::class, 'index'])->name('packages.index')->middleware('throttle:120,1');
            Route::get('/packages/{package}',    [AdminPackageController::class, 'show'])->whereNumber('package')->name('packages.show')->middleware('throttle:120,1');
            Route::post('/packages',             [AdminPackageController::class, 'store'])->name('packages.store')->middleware('throttle:30,1');
            Route::patch('/packages/{package}',  [AdminPackageController::class, 'update'])->whereNumber('package')->name('packages.update')->middleware('throttle:30,1');
            Route::delete('/packages/{package}', [AdminPackageController::class, 'destroy'])->whereNumber('package')->name('packages.destroy')->middleware('throttle:30,1');

            /* ========== Customers (companies) ========== */
            Route::get('/customers',               [AdminCustomerController::class, 'index'])->name('customers.index')->middleware('throttle:120,1');
            Route::get('/customers/{customer}',    [AdminCustomerController::class, 'show'])->whereNumber('customer')->name('customers.show')->middleware('throttle:120,1');
            Route::post('/customers',              [AdminCustomerController::class, 'store'])->name('customers.store')->middleware('throttle:30,1');
            Route::patch('/customers/{customer}',  [AdminCustomerController::class, 'update'])->whereNumber('customer')->name('customers.update')->middleware('throttle:30,1');
            Route::delete('/customers/{customer}', [AdminCustomerController::class, 'destroy'])->whereNumber('customer')->name('customers.destroy')->middleware('throttle:30,1');

            /* ========== Tenant Users (manager/supervisor) ========== */
            Route::get('/users',            [AdminCustomerUserController::class, 'index'])->name('users.index')->middleware('throttle:120,1');
            Route::get('/users/{user}',     [AdminCustomerUserController::class, 'show'])->whereNumber('user')->name('users.show')->middleware('throttle:120,1');
            Route::post('/users',           [AdminCustomerUserController::class, 'store'])->name('users.store')->middleware('throttle:30,1');
            Route::patch('/users/{user}',   [AdminCustomerUserController::class, 'update'])->whereNumber('user')->name('users.update')->middleware('throttle:30,1');
            Route::delete('/users/{user}',  [AdminCustomerUserController::class, 'destroy'])->whereNumber('user')->name('users.destroy')->middleware('throttle:30,1');

            /* ========== Enrollment Codes (claim codes for devices) ========== */
            Route::post('/enrollment-codes', [AdminEnrollmentCodeController::class, 'store'])->name('enrollment_codes.store');
            Route::get('/enrollment-codes',  [AdminEnrollmentCodeController::class, 'index'])->name('enrollment_codes.index');

            /* ========== CONTENT: Playlists (GLOBAL) ========== */
            Route::get('/playlists',               [AdminPlaylistController::class, 'index'])->name('playlists.index');
            Route::get('/playlists/{playlist}',    [AdminPlaylistController::class, 'show'])->whereNumber('playlist')->name('playlists.show');
            Route::post('/playlists',              [AdminPlaylistController::class, 'store'])->name('playlists.store');
            Route::patch('/playlists/{playlist}',  [AdminPlaylistController::class, 'update'])->whereNumber('playlist')->name('playlists.update');
            Route::delete('/playlists/{playlist}', [AdminPlaylistController::class, 'destroy'])->whereNumber('playlist')->name('playlists.destroy');

            /* Publish / Default / Refresh version */
            Route::post('/playlists/{playlist}/publish', [AdminPlaylistController::class, 'publish'])->whereNumber('playlist')->name('playlists.publish');
            Route::post('/playlists/{playlist}/default', [AdminPlaylistController::class, 'setDefault'])->whereNumber('playlist')->name('playlists.default');
            Route::post('/playlists/{playlist}/refresh', [AdminPlaylistController::class, 'refreshVersion'])->whereNumber('playlist')->name('playlists.refresh');

            /* Playlist Items (GLOBAL) */
            Route::post('/playlists/{playlist}/items',               [AdminPlaylistItemController::class, 'store'])->whereNumber('playlist')->name('playlist_items.store');
            Route::patch('/playlists/{playlist}/items/{item}',       [AdminPlaylistItemController::class, 'update'])->whereNumber('playlist')->whereNumber('item')->name('playlist_items.update');
            Route::delete('/playlists/{playlist}/items/{item}',      [AdminPlaylistItemController::class, 'destroy'])->whereNumber('playlist')->whereNumber('item')->name('playlist_items.destroy');
            Route::patch('/playlists/{playlist}/items/reorder',      [AdminPlaylistItemController::class, 'reorder'])->whereNumber('playlist')->name('playlist_items.reorder');

            /* ========== CONTENT: Screens (GLOBAL) ========== */
            /* Screens listing / details (for global admin) */
            Route::get('/screens',         [AdminScreenController::class, 'index'])->name('screens.index');
            Route::get('/screens/{screen}',[AdminScreenController::class, 'show'])->whereNumber('screen')->name('screens.show');
            Route::delete('/screens',      [AdminScreenController::class, 'destroy'])->name('screens.destroy'); // body: screen_ids[]

            /* CONTENT → Per-screen assign/refresh (GLOBAL) */
            Route::patch('/screens/{screen}/playlist', [AdminScreenContentController::class, 'setPlaylist'])->whereNumber('screen')->name('screens.set_playlist');
            Route::post('/screens/{screen}/refresh',   [AdminScreenContentController::class, 'refreshScreen'])->whereNumber('screen')->name('screens.refresh');

            /* CONTENT → BULK ops (GLOBAL) */
            Route::patch('/screens/playlist/all',                    [AdminContentBulkController::class, 'assignPlaylistToAllScreens'])->name('bulk.all.assign');
            Route::patch('/companies/{customer}/screens/playlist',   [AdminContentBulkController::class, 'assignPlaylistToCompanyScreens'])->whereNumber('customer')->name('bulk.company.assign');
            Route::patch('/screens/playlist',                        [AdminContentBulkController::class, 'assignPlaylistToScreens'])->name('bulk.screens.assign'); // body: screen_ids[]
            Route::post('/companies/{customer}/broadcast-config',    [AdminContentBulkController::class, 'broadcastCustomerConfig'])->whereNumber('customer')->name('bulk.company.broadcast');
            Route::post('/screens/broadcast-config',                 [AdminContentBulkController::class, 'broadcastScreensConfig'])->name('bulk.screens.broadcast'); // body: screen_ids[]

            /* Admin Dashboard API's */
            Route::get('/dashboard/summary',   [AdminDashboardController::class, 'summary'])->name('dashboard.summary');
            Route::get('/dashboard/screens',   [AdminDashboardController::class, 'screens'])->name('dashboard.screens');
            Route::get('/dashboard/customers', [AdminDashboardController::class, 'customers'])->name('dashboard.customers');
            Route::get('/dashboard/metrics',           [AdminDashboardController::class, 'metrics'])->name('dashboard.metrics');
            Route::get('/dashboard/licenses/expiring', [AdminDashboardController::class, 'licensesExpiring'])->name('dashboard.licenses.expiring');

            /* ===== Realtime push to screens (WS bump) ===== */
            Route::post('/screens/{screen}/push', [PlaylistPushController::class, 'push'])
                ->whereNumber('screen')->name('screens.push'); // يستخدم ScreenPushService ويرسل playlist.bump
        
    });
});

/* =================================================================== */
/*                        TENANT USERS API (v1)                        */
/* =================================================================== */

Route::prefix('user/v1')
    ->as('user.v1.')
    ->middleware('force.json')
    ->group(function () {

        /* --- Auth (token) ---------------------------------------------- */
        Route::post('/login', [UserTokenController::class, 'login'])
            ->name('auth.login')
            ->middleware('throttle:20,1');

        /* --- Protected (must be authenticated + real user) ------------- */
        Route::middleware(['auth:sanctum', 'api.user'])->group(function () {

            /* Self */
            Route::get('/me',          [UserTokenController::class, 'me'])->name('auth.me');
            Route::post('/logout',     [UserTokenController::class, 'logout'])->name('auth.logout');
            Route::post('/logout-all', [UserTokenController::class, 'logoutAll'])->name('auth.logout_all');

            /* Dashboard */
            Route::get('/dashboard/summary', [UserDashboardController::class, 'summary'])
                ->name('dashboard.summary');

            Route::get('/dashboard/metrics', [UserDashboardController::class, 'metrics'])
                ->name('dashboard.metrics');

            /* Screens (read) */
            Route::get('/screens', [UserScreenController::class, 'index'])
                ->name('screens.index');

            Route::get('/screens/{screen}', [UserScreenController::class, 'show'])
                ->whereNumber('screen')
                ->name('screens.show');

            /* ---------------- Manager-only APIs ---------------- */

            

                /* Supervisor Management */
                Route::get('/supervisors', [UserSupervisorController::class, 'index'])
                    ->name('supervisors.index')->middleware('user.manager');

                Route::post('/supervisors', [UserSupervisorController::class, 'store'])
                    ->name('supervisors.store')->middleware('user.manager');
            
                /* Assign/unassign supervisor to screen */
                Route::patch('/screens/{screen}/assign', [UserScreenAssignController::class, 'assign'])
                    ->whereNumber('screen')
                    ->name('screens.assign')
                    ->middleware('throttle:60,1');

                Route::patch('/screens/{screen}/unassign', [UserScreenAssignController::class, 'unassign'])
                    ->whereNumber('screen')
                    ->name('screens.unassign')
                    ->middleware('throttle:60,1');

                /* CONTENT → Per-screen assign/refresh */
                Route::patch('/screens/{screen}/playlist', [TenantScreenContentController::class, 'setPlaylist'])
                    ->whereNumber('screen')
                    ->name('screens.set_playlist');

                Route::post('/screens/{screen}/refresh', [TenantScreenContentController::class, 'refreshScreen'])
                    ->whereNumber('screen')
                    ->name('screens.refresh');

                /* CONTENT → BULK */
                Route::patch('/company/screens/playlist', [TenantContentBulkController::class, 'assignPlaylistToCompanyScreens'])
                    ->name('bulk.company.assign');

                Route::patch('/screens/playlist', [TenantContentBulkController::class, 'assignPlaylistToScreens'])
                    ->name('bulk.screens.assign');

                Route::post('/company/broadcast-config', [TenantContentBulkController::class, 'broadcastCompanyConfig'])
                    ->name('bulk.company.broadcast');

                Route::post('/screens/broadcast-config', [TenantContentBulkController::class, 'broadcastScreensConfig'])
                    ->name('bulk.screens.broadcast');

                /* COMPANY Playlists (Manager only) */
                Route::get('/playlists', [CompanyPlaylistController::class, 'index'])
                    ->name('playlists.index');

                Route::get('/playlists/{playlist}', [CompanyPlaylistController::class, 'show'])
                    ->whereNumber('playlist')
                    ->name('playlists.show');

                Route::post('/playlists', [CompanyPlaylistController::class, 'store'])
                    ->name('playlists.store');

                Route::patch('/playlists/{playlist}', [CompanyPlaylistController::class, 'update'])
                    ->whereNumber('playlist')
                    ->name('playlists.update');

                Route::delete('/playlists/{playlist}', [CompanyPlaylistController::class, 'destroy'])
                    ->whereNumber('playlist')
                    ->name('playlists.destroy');

                Route::post('/playlists/{playlist}/publish', [CompanyPlaylistController::class, 'publish'])
                    ->whereNumber('playlist')
                    ->name('playlists.publish');

                Route::post('/playlists/{playlist}/default', [CompanyPlaylistController::class, 'setDefault'])
                    ->whereNumber('playlist')
                    ->name('playlists.default');

                Route::post('/playlists/{playlist}/refresh', [CompanyPlaylistController::class, 'refreshVersion'])
                    ->whereNumber('playlist')
                    ->name('playlists.refresh');

                Route::post('/playlists/{playlist}/items', [CompanyPlaylistItemController::class, 'store'])
                    ->whereNumber('playlist')
                    ->name('playlist_items.store');

                Route::patch('/playlists/{playlist}/items/{item}', [CompanyPlaylistItemController::class, 'update'])
                    ->whereNumber('playlist')
                    ->whereNumber('item')
                    ->name('playlist_items.update');

                Route::delete('/playlists/{playlist}/items/{item}', [CompanyPlaylistItemController::class, 'destroy'])
                    ->whereNumber('playlist')
                    ->whereNumber('item')
                    ->name('playlist_items.destroy');

                Route::patch('/playlists/{playlist}/items/reorder', [CompanyPlaylistItemController::class, 'reorder'])
                    ->whereNumber('playlist')
                    ->name('playlist_items.reorder');
            
        });
    });

/* =================================================================== */
/*                          DEVICE API (v1)                            */
/* =================================================================== */
/* Note: 'screen.auth' authenticates device via X-Screen-Token header. */
Route::prefix('screen/v1')->as('screen.v1.')->middleware('force.json')->group(function () {
    Route::post('/register',  [ScreenController::class, 'register'])
        ->name('register')->middleware('throttle:10,1');

    Route::post('/heartbeat', [ScreenController::class, 'heartbeat'])
        ->name('heartbeat')->middleware('screen.auth','throttle:60,1');

    Route::get('/config',     [ScreenController::class, 'config'])
        ->name('config')->middleware('screen.auth','throttle:60,1');

    // تأكد من وجود دالة public اسمها playlistJson في ScreenController
    Route::get('/playlist',   [ScreenController::class, 'playlistJson'])
        ->name('playlist')->middleware('screen.auth','throttle:60,1');
});

/* =================================================================== */
/*                        WEBSOCKET HELPERS                            */
/* =================================================================== */

/* Resolve token -> screen room (للاستخدام من بوابة WS فقط) */
Route::get('/ws/resolve', function (\Illuminate\Http\Request $request) {
    $token = $request->query('token');
    if (!$token) return response()->json(['ok'=>false,'error'=>'missing token'], 400);

    $screen = \App\Models\Screen::select('id','customer_id')
        ->where('api_token', $token)->first();

    if (!$screen) return response()->json(['ok'=>false,'error'=>'invalid token'], 404);

    return response()->json([
        'ok' => true,
        'screen' => ['id' => $screen->id, 'customer_id' => $screen->customer_id],
    ]);
});

/* ممر سريع (اختياري) لدفع إشعار bump لشاشة محددة من الأدمن */
Route::post('/admin/v1/screens/{screen}/push', [PlaylistPushController::class, 'push'])
    ->whereNumber('screen')
    ->middleware('force.json'); // يمكن نقلها داخل مجموعة admin/auth إذا رغبت
