<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FacebookOAuthController;
use App\Http\Controllers\HashtagController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublishController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SocialAccountController;
use App\Http\Controllers\ThreadsOAuthController;
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

    // Facebook/Instagram OAuth flow
    Route::get('auth/facebook/redirect', [FacebookOAuthController::class, 'redirect'])->name('facebook.redirect');
    Route::get('auth/facebook/callback', [FacebookOAuthController::class, 'callback'])->name('facebook.callback');
    Route::get('auth/facebook/select', [FacebookOAuthController::class, 'select'])->name('facebook.select');
    Route::post('auth/facebook/connect', [FacebookOAuthController::class, 'connect'])->name('facebook.connect');

    // Threads OAuth flow
    Route::get('auth/threads/redirect', [ThreadsOAuthController::class, 'redirect'])->name('threads.redirect');
    Route::get('auth/threads/callback', [ThreadsOAuthController::class, 'callback'])->name('threads.callback');

    // Platforms management (one page per platform)
    Route::get('platforms/facebook', [PlatformController::class, 'facebook'])->name('platforms.facebook');
    Route::get('platforms/threads', [PlatformController::class, 'threads'])->name('platforms.threads');
    Route::get('platforms/telegram', [PlatformController::class, 'telegram'])->name('platforms.telegram');
    Route::get('platforms/twitter', [PlatformController::class, 'twitter'])->name('platforms.twitter');
    Route::post('platforms/telegram/validate-bot', [PlatformController::class, 'validateTelegramBot'])->name('platforms.telegram.validateBot');
    Route::post('platforms/telegram/register-bot', [PlatformController::class, 'registerTelegramBot'])->name('platforms.telegram.registerBot');
    Route::post('platforms/telegram/add-channel', [PlatformController::class, 'addTelegramChannel'])->name('platforms.telegram.addChannel');
    Route::delete('platforms/telegram/bot', [PlatformController::class, 'destroyTelegramBot'])->name('platforms.telegram.destroyBot');
    Route::post('platforms/twitter/add-account', [PlatformController::class, 'addTwitterAccount'])->name('platforms.twitter.addAccount');
    Route::post('platforms/twitter/validate-account', [PlatformController::class, 'validateTwitterAccount'])->name('platforms.twitter.validateAccount');
    Route::delete('platforms/account/{account}', [PlatformController::class, 'destroyAccount'])->name('platforms.destroyAccount');

    // Save default account selection
    Route::post('posts/default-accounts', [PostController::class, 'saveDefaultAccounts'])->name('posts.defaultAccounts');

    // Manual publishing (test without scheduling)
    Route::post('posts/{post}/publish', [PublishController::class, 'publishAll'])->name('posts.publish');
    Route::post('posts/platform/{postPlatform}/publish', [PublishController::class, 'publishOne'])->name('posts.publishOne');
    Route::post('posts/platform/{postPlatform}/reset', [PublishController::class, 'resetOne'])->name('posts.resetOne');

    // Settings (admin only)
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::patch('settings', [SettingsController::class, 'update'])->name('settings.update');

    // Location search (Facebook Places API)
    Route::get('api/locations/search', [LocationController::class, 'search'])->name('locations.search');

    // Hashtags (most used)
    Route::get('api/hashtags', [HashtagController::class, 'index'])->name('hashtags.index');

    // Media library
    Route::get('media', [MediaController::class, 'index'])->name('media.index');
    Route::post('media/upload', [MediaController::class, 'upload'])->name('media.upload');
    Route::get('media/list', [MediaController::class, 'list'])->name('media.list');
    Route::get('media/thumbnail/{filename}', [MediaController::class, 'thumbnail'])->name('media.thumbnail');
    Route::delete('media/{filename}', [MediaController::class, 'destroy'])->name('media.destroy');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Media file serving: auth OR signed URL (for external platform APIs)
// Must come after the auth group so media/upload and media/list are matched first
Route::get('/media/{filename}', [MediaController::class, 'show'])
    ->where('filename', '[^/]+\.[a-zA-Z0-9]+')
    ->name('media.show');

require __DIR__.'/auth.php';
