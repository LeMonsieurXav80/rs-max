<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SocialAccountController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect('/dashboard') : redirect('/login');
});

Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Posts (resource CRUD)
    Route::resource('posts', PostController::class);

    // Social accounts (resource CRUD + toggle active)
    Route::resource('accounts', SocialAccountController::class)->except(['show']);
    Route::patch('accounts/{account}/toggle', [SocialAccountController::class, 'toggleActive'])
        ->name('accounts.toggle');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Media: auth OR signed URL (for external platform APIs)
Route::get('/media/{filename}', [MediaController::class, 'show'])->name('media.show');

require __DIR__.'/auth.php';
