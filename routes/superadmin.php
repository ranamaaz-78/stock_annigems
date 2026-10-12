<?php

use App\Http\Controllers\LoginAsController;
use App\Http\Controllers\SuperAdmin\BranchController;
use App\Http\Controllers\SuperAdmin\DashboardController;
use App\Http\Controllers\SuperAdmin\ReportController;
use App\Http\Controllers\SuperAdmin\SettingController;
use App\Http\Controllers\SuperAdmin\UserController;
use Illuminate\Support\Facades\Route;

/**
 * SuperAdmin area routes.
 *
 * Protected by auth + EnsureSuperAdmin + password-changed + branch context.
 * Policies provide a second authorization layer inside controllers.
 *
 * `{branch}` and `{user}` bind on uuid (HasUuid::getRouteKeyName), never on
 * the integer primary key. Foreign keys and Eloquent relations still use id.
 */
Route::middleware(['auth', 'password.changed', 'superadmin', 'branch.context'])
    ->prefix('superadmin')
    ->name('superadmin.')
    ->group(function (): void {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
        Route::get('/branches/create', [BranchController::class, 'create'])->name('branches.create');
        Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
        Route::get('/branches/{branch}', [BranchController::class, 'show'])->name('branches.show');
        Route::get('/branches/{branch}/edit', [BranchController::class, 'edit'])->name('branches.edit');
        Route::put('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
        Route::post('/branches/{branch}/deactivate', [BranchController::class, 'deactivate'])->name('branches.deactivate');
        Route::post('/branches/{branch}/activate', [BranchController::class, 'activate'])->name('branches.activate');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create-admin', [UserController::class, 'createAdmin'])->name('users.create-admin');
        Route::post('/users/create-admin', [UserController::class, 'storeAdmin'])->name('users.store-admin');
        Route::get('/users/{user}', [UserController::class, 'show'])->withTrashed()->name('users.show');
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])->withTrashed()->name('users.deactivate');
        Route::post('/users/{user}/activate', [UserController::class, 'activate'])->withTrashed()->name('users.activate');
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->withTrashed()->name('users.reset-password');
        Route::post('/users/{user}/login-as', [LoginAsController::class, 'store'])->name('users.login-as');

        Route::get('/gold-rates', [\App\Http\Controllers\SuperAdmin\GoldRateController::class, 'index'])->name('gold-rates.index');
        Route::post('/gold-rates', [\App\Http\Controllers\SuperAdmin\GoldRateController::class, 'store'])->name('gold-rates.store');

        /**
         * Aggregate reports (Module 6).
         *
         * Deliberately narrow: the only route parameters are a report slug and
         * a ReportExport binding. No route here accepts an invoice, quotation,
         * customer, jewellery item or stone uuid, which is what makes the
         * no-drill-through rule in spec 7.4 structural rather than a check
         * someone could later forget to write.
         */
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/exports/{reportExport}', [ReportController::class, 'download'])->name('reports.download');
        Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show');
        Route::get('/reports/{report}/export', [ReportController::class, 'export'])
            ->middleware('throttle:report-export')
            ->name('reports.export');

        /**
         * Global settings (Module 7). History is a named path so it cannot
         * be captured by the {group} segment.
         */
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::get('/settings/history', [SettingController::class, 'history'])->name('settings.history');
        Route::put('/settings/{group}', [SettingController::class, 'update'])->name('settings.update');
    });
