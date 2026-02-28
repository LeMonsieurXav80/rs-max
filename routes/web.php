<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FacebookOAuthController;
use App\Http\Controllers\HashtagController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PersonaController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublishController;
use App\Http\Controllers\AiAssistController;
use App\Http\Controllers\RssFeedController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\RedditSourceController;
use App\Http\Controllers\WordPressSiteController;
use App\Http\Controllers\SocialAccountController;
use App\Http\Controllers\YouTubeChannelController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\ThreadController;
use App\Http\Controllers\ThreadsOAuthController;
use App\Http\Controllers\YouTubeOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect('/dashboard') : redirect('/login');
});

Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // AI Assist (must be before posts resource to avoid route conflict)
    Route::post('posts/ai-assist', [AiAssistController::class, 'generate'])->name('posts.aiAssist');
    Route::post('posts/ai-assist-platforms', [AiAssistController::class, 'generateForPlatforms'])->name('posts.aiAssistPlatforms');
    Route::post('posts/ai-assist-media', [AiAssistController::class, 'generateFromMedia'])->name('posts.aiAssistMedia');

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

    // YouTube OAuth flow
    Route::get('oauth/youtube/redirect', [YouTubeOAuthController::class, 'redirect'])->name('youtube.redirect');
    Route::get('oauth/youtube/callback', [YouTubeOAuthController::class, 'callback'])->name('youtube.callback');
    Route::get('oauth/youtube/select', [YouTubeOAuthController::class, 'select'])->name('youtube.select');
    Route::post('oauth/youtube/store', [YouTubeOAuthController::class, 'store'])->name('youtube.store');

    // Platforms management (one page per platform)
    Route::get('platforms/facebook', [PlatformController::class, 'facebook'])->name('platforms.facebook');
    Route::get('platforms/threads', [PlatformController::class, 'threads'])->name('platforms.threads');
    Route::get('platforms/telegram', [PlatformController::class, 'telegram'])->name('platforms.telegram');
    Route::get('platforms/twitter', [PlatformController::class, 'twitter'])->name('platforms.twitter');
    Route::get('platforms/youtube', [PlatformController::class, 'youtube'])->name('platforms.youtube');
    Route::post('platforms/telegram/validate-bot', [PlatformController::class, 'validateTelegramBot'])->name('platforms.telegram.validateBot');
    Route::post('platforms/telegram/register-bot', [PlatformController::class, 'registerTelegramBot'])->name('platforms.telegram.registerBot');
    Route::post('platforms/telegram/add-channel', [PlatformController::class, 'addTelegramChannel'])->name('platforms.telegram.addChannel');
    Route::delete('platforms/telegram/bot', [PlatformController::class, 'destroyTelegramBot'])->name('platforms.telegram.destroyBot');
    Route::post('platforms/twitter/add-account', [PlatformController::class, 'addTwitterAccount'])->name('platforms.twitter.addAccount');
    Route::post('platforms/twitter/validate-account', [PlatformController::class, 'validateTwitterAccount'])->name('platforms.twitter.validateAccount');
    Route::delete('platforms/account/{account}', [PlatformController::class, 'destroyAccount'])->name('platforms.destroyAccount');

    // Save default account selection
    Route::post('posts/default-accounts', [PostController::class, 'saveDefaultAccounts'])->name('posts.defaultAccounts');

    // Stats management
    Route::post('posts/{post}/sync-stats', [PostController::class, 'syncStats'])->name('posts.syncStats');
    Route::get('stats/dashboard', [StatsController::class, 'dashboard'])->name('stats.dashboard');

    // Historical import
    Route::get('accounts/{account}/import/info', [ImportController::class, 'info'])->name('accounts.import.info');
    Route::post('accounts/{account}/import', [ImportController::class, 'import'])->name('accounts.import');

    // Followers sync
    Route::post('accounts/sync-followers', [ImportController::class, 'syncFollowers'])->name('accounts.syncFollowers');

    // Manual publishing (test without scheduling)
    Route::post('posts/{post}/publish', [PublishController::class, 'publishAll'])->name('posts.publish');
    Route::post('posts/platform/{postPlatform}/publish', [PublishController::class, 'publishOne'])->name('posts.publishOne');
    Route::post('posts/platform/{postPlatform}/reset', [PublishController::class, 'resetOne'])->name('posts.resetOne');

    // Threads (Fils de discussion)
    Route::post('threads/generate-from-url', [ThreadController::class, 'generateFromUrl'])->name('threads.generateFromUrl');
    Route::post('threads/regenerate-segment', [ThreadController::class, 'regenerateSegment'])->name('threads.regenerateSegment');
    Route::resource('threads', ThreadController::class);
    Route::post('threads/{thread}/publish', [ThreadController::class, 'publishAll'])->name('threads.publish');
    Route::post('threads/{thread}/publish/{socialAccount}', [ThreadController::class, 'publishOne'])->name('threads.publishOne');
    Route::post('threads/{thread}/reset/{socialAccount}', [ThreadController::class, 'resetAccount'])->name('threads.resetAccount');

    // Personas (admin)
    Route::resource('personas', PersonaController::class)->except(['show']);

    // RSS Feeds (admin)
    Route::resource('rss-feeds', RssFeedController::class)->except(['show']);
    Route::post('rss-feeds/{rssFeed}/fetch', [RssFeedController::class, 'fetchNow'])->name('rss-feeds.fetch');
    Route::post('rss-feeds/{rssFeed}/generate', [RssFeedController::class, 'generateNow'])->name('rss-feeds.generate');
    Route::get('rss-feeds/{rssFeed}/preview', [RssFeedController::class, 'preview'])->name('rss-feeds.preview');
    Route::post('rss-feeds/{rssFeed}/generate-preview', [RssFeedController::class, 'generatePreview'])->name('rss-feeds.generatePreview');
    Route::post('rss-feeds/{rssFeed}/regenerate-item', [RssFeedController::class, 'regenerateItem'])->name('rss-feeds.regenerateItem');
    Route::post('rss-feeds/{rssFeed}/confirm-publications', [RssFeedController::class, 'confirmPublications'])->name('rss-feeds.confirmPublications');

    // WordPress Sites (admin)
    Route::resource('wordpress-sites', WordPressSiteController::class)->except(['show'])->parameters(['wordpress-sites' => 'wpSource']);
    Route::post('wordpress-sites/test-connection', [WordPressSiteController::class, 'testConnection'])->name('wordpress-sites.testConnection');
    Route::post('wordpress-sites/{wpSource}/fetch', [WordPressSiteController::class, 'fetchNow'])->name('wordpress-sites.fetch');
    Route::get('wordpress-sites/{wpSource}/preview', [WordPressSiteController::class, 'preview'])->name('wordpress-sites.preview');
    Route::post('wordpress-sites/{wpSource}/generate-preview', [WordPressSiteController::class, 'generatePreview'])->name('wordpress-sites.generatePreview');
    Route::post('wordpress-sites/{wpSource}/regenerate-item', [WordPressSiteController::class, 'regenerateItem'])->name('wordpress-sites.regenerateItem');
    Route::post('wordpress-sites/{wpSource}/confirm-publications', [WordPressSiteController::class, 'confirmPublications'])->name('wordpress-sites.confirmPublications');

    // YouTube Channels (admin)
    Route::resource('youtube-channels', YouTubeChannelController::class)->except(['show'])->parameters(['youtube-channels' => 'ytSource']);
    Route::post('youtube-channels/test-connection', [YouTubeChannelController::class, 'testConnection'])->name('youtube-channels.testConnection');
    Route::post('youtube-channels/{ytSource}/fetch', [YouTubeChannelController::class, 'fetchNow'])->name('youtube-channels.fetch');
    Route::get('youtube-channels/{ytSource}/preview', [YouTubeChannelController::class, 'preview'])->name('youtube-channels.preview');
    Route::post('youtube-channels/{ytSource}/generate-preview', [YouTubeChannelController::class, 'generatePreview'])->name('youtube-channels.generatePreview');
    Route::post('youtube-channels/{ytSource}/regenerate-item', [YouTubeChannelController::class, 'regenerateItem'])->name('youtube-channels.regenerateItem');
    Route::post('youtube-channels/{ytSource}/confirm-publications', [YouTubeChannelController::class, 'confirmPublications'])->name('youtube-channels.confirmPublications');

    // Reddit Sources (admin)
    Route::resource('reddit-sources', RedditSourceController::class)->except(['show'])->parameters(['reddit-sources' => 'redditSource']);
    Route::post('reddit-sources/test-connection', [RedditSourceController::class, 'testConnection'])->name('reddit-sources.testConnection');
    Route::post('reddit-sources/{redditSource}/fetch', [RedditSourceController::class, 'fetchNow'])->name('reddit-sources.fetch');
    Route::get('reddit-sources/{redditSource}/preview', [RedditSourceController::class, 'preview'])->name('reddit-sources.preview');
    Route::post('reddit-sources/{redditSource}/generate-preview', [RedditSourceController::class, 'generatePreview'])->name('reddit-sources.generatePreview');
    Route::post('reddit-sources/{redditSource}/regenerate-item', [RedditSourceController::class, 'regenerateItem'])->name('reddit-sources.regenerateItem');
    Route::post('reddit-sources/{redditSource}/confirm-publications', [RedditSourceController::class, 'confirmPublications'])->name('reddit-sources.confirmPublications');

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
