<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('forgot-password', [\App\Http\Controllers\Settings\PasswordRecoveryController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [\App\Http\Controllers\Settings\PasswordRecoveryController::class, 'store'])->middleware('throttle:3,1')->name('password.email');
    Route::get('reset-password/{token}', [\App\Http\Controllers\Settings\PasswordRecoveryController::class, 'edit'])->name('password.reset');
    Route::post('reset-password', [\App\Http\Controllers\Settings\PasswordRecoveryController::class, 'update'])->middleware('throttle:6,1')->name('password.update');
});

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('settings/telegram-link', [\App\Http\Controllers\Settings\TelegramLinkController::class, 'store'])
        ->middleware(['password.confirm', 'throttle:3,1'])->name('telegram-link.store');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/password', [PasswordController::class, 'edit'])->name('user-password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    Route::get('settings/two-factor', [TwoFactorAuthenticationController::class, 'show'])
        ->name('two-factor.show');
});
