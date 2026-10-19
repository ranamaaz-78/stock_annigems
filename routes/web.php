<?php

use App\Http\Controllers\Auth\ForceChangePasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\PublicJewelleryItemController;
use Illuminate\Support\Facades\Route;

/**
 * Web routes — guest auth, shared authenticated routes, and includes.
 */

Route::redirect('/', '/login');

// Language button: open to guests (login screen) and signed-in users alike.
Route::post('/locale/{locale}', [\App\Http\Controllers\LocaleController::class, 'update'])
    ->whereIn('locale', array_keys(config('app.supported_locales', [])))
    ->name('locale.switch');

Route::get('/session-expired', function () {
    return view('errors.session-expired');
})->name('session.expired');

Route::middleware('throttle:public-item')->group(function (): void {
    Route::get('/item/{qrToken}', [PublicJewelleryItemController::class, 'show'])->name('public.jewellery-items.show');
    Route::get('/item/{qrToken}/photo/{photo}', [PublicJewelleryItemController::class, 'image'])
        ->whereNumber('photo')
        ->name('public.jewellery-items.image');
});

Route::middleware('throttle:invoice-verify')->group(function (): void {
    Route::get('/verify/{token}', [\App\Http\Controllers\PublicInvoiceController::class, 'show'])->name('public.invoices.verify');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);

    Route::get('/forgot-password', [PasswordResetController::class, 'requestForm'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.update');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');


    Route::get('/password/change', [ForceChangePasswordController::class, 'edit'])->name('password.change');
    Route::post('/password/change', [ForceChangePasswordController::class, 'update'])->name('password.change.update');
});

require __DIR__.'/branch.php';
require __DIR__.'/superadmin.php';
