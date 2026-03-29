<?php

use App\Http\Controllers\AccountGroupController;
use App\Http\Controllers\BotController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FacebookOAuthController;
use App\Http\Controllers\HashtagController;
use App\Http\Controllers\HookController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MediaFolderController;
use App\Http\Controllers\MediaStudioController;
use App\Http\Controllers\PersonaController;
use App\Http\Controllers\PromptGeneratorController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublishController;
use App\Http\Controllers\AiAssistController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\RssFeedController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SourceItemController;
use App\Http\Controllers\RedditSourceController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\CrossPostController;
use App\Http\Controllers\WordPressSiteController;
use App\Http\Controllers\SocialAccountController;
use App\Http\Controllers\YouTubeChannelController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\ThreadController;
use App\Http\Controllers\LinkedInOAuthController;
use App\Http\Controllers\ThreadsOAuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\UpdateController;
use App\Http\Controllers\YouTubeOAuthController;
use App\Http\Controllers\MediaTemplateController;
use App\Http\Controllers\PinterestFeedController;
use App\Http\Controllers\PinterestOAuthController;
use App\Http\Controllers\YouTubeTranslatorController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect('/dashboard') : redirect('/login');
});

Route::middleware(['auth', 'verified', 'throttle:60,1'])->group(function () {
    // ─── Accessible a tous les utilisateurs authentifies ──────────

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Help
    Route::get('/help', [HelpController::class, 'index'])->name('help.index');

    // AI Assist (must be before posts resource to avoid route conflict)
    Route::post('posts/ai-assist', [AiAssistController::class, 'generate'])->name('posts.aiAssist');
    Route::post('posts/ai-assist-platforms', [AiAssistController::class, 'generateForPlatforms'])->name('posts.aiAssistPlatforms');
    Route::post('posts/ai-assist-media', [AiAssistController::class, 'generateFromMedia'])->name('posts.aiAssistMedia');

    // Posts (resource CRUD)
    Route::resource('posts', PostController::class);

    // Social accounts (listing, editing, toggle)
    Route::get('accounts', [SocialAccountController::class, 'index'])->name('accounts.index');
    Route::get('accounts/{account}/edit', [SocialAccountController::class, 'edit'])->name('accounts.edit');
    Route::put('accounts/{account}', [SocialAccountController::class, 'update'])->name('accounts.update');
    Route::patch('accounts/{account}/toggle', [SocialAccountController::class, 'toggleActive'])->name('accounts.toggle');

    // Platform pages (view accounts per platform)
    Route::get('platforms/facebook', [PlatformController::class, 'facebook'])->name('platforms.facebook');
    Route::get('platforms/threads', [PlatformController::class, 'threads'])->name('platforms.threads');
    Route::get('platforms/telegram', [PlatformController::class, 'telegram'])->name('platforms.telegram');
    Route::get('platforms/twitter', [PlatformController::class, 'twitter'])->name('platforms.twitter');
    Route::get('platforms/youtube', [PlatformController::class, 'youtube'])->name('platforms.youtube');
    Route::get('platforms/bluesky', [PlatformController::class, 'bluesky'])->name('platforms.bluesky');
    Route::get('platforms/reddit', [PlatformController::class, 'reddit'])->name('platforms.reddit');
    Route::get('platforms/linkedin', [PlatformController::class, 'linkedin'])->name('platforms.linkedin');
    Route::get('platforms/pinterest', [PlatformController::class, 'pinterest'])->name('platforms.pinterest');

    // Platform validation (AJAX test connection — read-only, no cost concern)
    Route::post('platforms/telegram/validate-bot', [PlatformController::class, 'validateTelegramBot'])->name('platforms.telegram.validateBot');
    Route::post('platforms/twitter/validate-account', [PlatformController::class, 'validateTwitterAccount'])->name('platforms.twitter.validateAccount');
    Route::post('platforms/bluesky/validate-account', [PlatformController::class, 'validateBlueskyAccount'])->name('platforms.bluesky.validateAccount');
    Route::post('platforms/reddit/validate-account', [PlatformController::class, 'validateRedditAccount'])->name('platforms.reddit.validateAccount');

    // Save default account selection (shared across posts, inbox, stats)
    Route::post('accounts/save-defaults', [PostController::class, 'saveDefaultAccounts'])->name('accounts.saveDefaults');

    // Account groups
    Route::get('account-groups', [AccountGroupController::class, 'index'])->name('accountGroups.index');
    Route::post('account-groups', [AccountGroupController::class, 'store'])->name('accountGroups.store');
    Route::put('account-groups/{accountGroup}', [AccountGroupController::class, 'update'])->name('accountGroups.update');
    Route::delete('account-groups/{accountGroup}', [AccountGroupController::class, 'destroy'])->name('accountGroups.destroy');
    Route::post('account-groups/reorder', [AccountGroupController::class, 'reorder'])->name('accountGroups.reorder');

    // Messagerie (inbox)
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::post('inbox/mark-read', [InboxController::class, 'markRead'])->name('inbox.markRead');
    Route::post('inbox/archive', [InboxController::class, 'archive'])->name('inbox.archive');
    Route::post('inbox/ignore', [InboxController::class, 'ignore'])->name('inbox.ignore');
    Route::post('inbox/dismiss-failed', [InboxController::class, 'dismissFailed'])->name('inbox.dismissFailed');
    Route::post('inbox/{inboxItem}/reply', [InboxController::class, 'reply'])->name('inbox.reply');
    Route::post('inbox/{inboxItem}/ai-suggest', [InboxController::class, 'aiSuggest'])->name('inbox.aiSuggest');
    Route::post('inbox/bulk-ai-reply', [InboxController::class, 'bulkAiReply'])->name('inbox.bulkAiReply');
    Route::post('inbox/bulk-send', [InboxController::class, 'bulkSend'])->name('inbox.bulkSend');
    Route::get('inbox/scheduled-status', [InboxController::class, 'scheduledStatus'])->name('inbox.scheduledStatus');

    // Stats
    Route::post('posts/{post}/sync-stats', [PostController::class, 'syncStats'])->name('posts.syncStats');
    Route::get('stats', [StatsController::class, 'overview'])->name('stats.overview');
    Route::get('stats/audience', [StatsController::class, 'audience'])->name('stats.audience');
    Route::get('stats/publications', [StatsController::class, 'publications'])->name('stats.publications');
    Route::get('stats/platforms', [StatsController::class, 'platforms'])->name('stats.platforms');

    // Manual publishing
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

    // Location search (Facebook Places API)
    Route::get('api/locations/search', [LocationController::class, 'search'])->name('locations.search');

    // Hashtags (most used)
    Route::get('api/hashtags', [HashtagController::class, 'index'])->name('hashtags.index');

    // Media folders
    Route::get('media/folders', [MediaFolderController::class, 'index'])->name('media.folders.index');
    Route::post('media/folders', [MediaFolderController::class, 'store'])->name('media.folders.store');
    Route::patch('media/folders/{folder}', [MediaFolderController::class, 'update'])->name('media.folders.update');
    Route::delete('media/folders/{folder}', [MediaFolderController::class, 'destroy'])->name('media.folders.destroy');
    Route::post('media/folders/move', [MediaFolderController::class, 'moveFiles'])->name('media.folders.move');

    // Media Studio
    Route::get('media/studio', [MediaStudioController::class, 'index'])->name('media.studio');
    Route::post('media/studio/process', [MediaStudioController::class, 'process'])->name('media.studio.process');
    Route::post('media/studio/logo', [MediaStudioController::class, 'uploadLogo'])->name('media.studio.logo');

    // Media Templates
    Route::get('media/templates', [MediaTemplateController::class, 'index'])->name('media.templates');
    Route::post('media/templates', [MediaTemplateController::class, 'store'])->name('media.templates.store');
    Route::put('media/templates/{template}', [MediaTemplateController::class, 'update'])->name('media.templates.update');
    Route::delete('media/templates/{template}', [MediaTemplateController::class, 'destroy'])->name('media.templates.destroy');
    Route::post('media/templates/download-font', [MediaTemplateController::class, 'downloadFont'])->name('media.templates.downloadFont');
    Route::post('media/templates/{template}/preview', [MediaTemplateController::class, 'preview'])->name('media.templates.preview');

    // Prompt Generator
    Route::get('prompts/image', [PromptGeneratorController::class, 'image'])->name('prompts.image');
    Route::post('prompts/image/generate', [PromptGeneratorController::class, 'generateImage'])->name('prompts.image.generate');
    Route::get('prompts/video', [PromptGeneratorController::class, 'video'])->name('prompts.video');
    Route::post('prompts/video/analyze', [PromptGeneratorController::class, 'analyzePhoto'])->name('prompts.video.analyze');
    Route::post('prompts/video/generate', [PromptGeneratorController::class, 'generateVideo'])->name('prompts.video.generate');

    // Media library
    Route::get('media', [MediaController::class, 'index'])->name('media.index');
    Route::post('media/upload', [MediaController::class, 'upload'])->name('media.upload');
    Route::get('media/list', [MediaController::class, 'list'])->name('media.list');
    Route::get('media/thumbnail/{filename}', [MediaController::class, 'thumbnail'])->name('media.thumbnail');
    Route::delete('media/{filename}', [MediaController::class, 'destroy'])->name('media.destroy');

    // OAuth flows (reconnecting accounts the user already has linked)
    Route::get('auth/facebook/redirect', [FacebookOAuthController::class, 'redirect'])->name('facebook.redirect');
    Route::get('auth/facebook/callback', [FacebookOAuthController::class, 'callback'])->name('facebook.callback');
    Route::get('auth/facebook/select', [FacebookOAuthController::class, 'select'])->name('facebook.select');
    Route::post('auth/facebook/connect', [FacebookOAuthController::class, 'connect'])->name('facebook.connect');
    Route::get('auth/threads/redirect', [ThreadsOAuthController::class, 'redirect'])->name('threads.redirect');
    Route::get('auth/threads/callback', [ThreadsOAuthController::class, 'callback'])->name('threads.callback');
    Route::get('auth/linkedin/redirect', [LinkedInOAuthController::class, 'redirect'])->name('linkedin.redirect');
    Route::get('auth/linkedin/callback', [LinkedInOAuthController::class, 'callback'])->name('linkedin.callback');
    Route::get('auth/linkedin/select', [LinkedInOAuthController::class, 'select'])->name('linkedin.select');
    Route::post('auth/linkedin/connect', [LinkedInOAuthController::class, 'connect'])->name('linkedin.connect');
    Route::get('oauth/youtube/redirect', [YouTubeOAuthController::class, 'redirect'])->name('youtube.redirect');
    Route::get('oauth/youtube/callback', [YouTubeOAuthController::class, 'callback'])->name('youtube.callback');
    Route::get('oauth/youtube/select', [YouTubeOAuthController::class, 'select'])->name('youtube.select');
    Route::post('oauth/youtube/store', [YouTubeOAuthController::class, 'store'])->name('youtube.store');
    Route::get('auth/pinterest/redirect', [PinterestOAuthController::class, 'redirect'])->name('pinterest.redirect');
    Route::get('auth/pinterest/callback', [PinterestOAuthController::class, 'callback'])->name('pinterest.callback');
    Route::get('auth/pinterest/select', [PinterestOAuthController::class, 'select'])->name('pinterest.select');
    Route::post('auth/pinterest/connect', [PinterestOAuthController::class, 'connect'])->name('pinterest.connect');

    // Platform account management (update credentials — user must own the account)
    Route::post('platforms/telegram/register-bot', [PlatformController::class, 'registerTelegramBot'])->name('platforms.telegram.registerBot');
    Route::post('platforms/telegram/add-channel', [PlatformController::class, 'addTelegramChannel'])->name('platforms.telegram.addChannel');
    Route::post('platforms/twitter/add-account', [PlatformController::class, 'addTwitterAccount'])->name('platforms.twitter.addAccount');
    Route::put('platforms/twitter/update-account/{account}', [PlatformController::class, 'updateTwitterAccount'])->name('platforms.twitter.updateAccount');
    Route::post('platforms/bluesky/add-account', [PlatformController::class, 'addBlueskyAccount'])->name('platforms.bluesky.addAccount');
    Route::post('platforms/reddit/register-app', [PlatformController::class, 'registerRedditApp'])->name('platforms.reddit.registerApp');
    Route::post('platforms/reddit/add-subreddit', [PlatformController::class, 'addRedditSubreddit'])->name('platforms.reddit.addSubreddit');

    // ─── Manager (gestionnaire) ─────────────────────────────────

    Route::middleware('role:manager')->group(function () {
        // Inbox sync
        Route::post('inbox/sync', [InboxController::class, 'sync'])->name('inbox.sync');

        // Historical import & followers sync
        Route::get('accounts/{account}/import/info', [ImportController::class, 'info'])->name('accounts.import.info');
        Route::post('accounts/{account}/import', [ImportController::class, 'import'])->name('accounts.import');
        Route::post('accounts/sync-followers', [ImportController::class, 'syncFollowers'])->name('accounts.syncFollowers');

        // Personas
        Route::resource('personas', PersonaController::class)->except(['show']);

        // Hooks
        Route::resource('hooks', HookController::class)->except(['show']);
        Route::post('hooks/categories', [HookController::class, 'storeCategory'])->name('hooks.categories.store');
        Route::patch('hooks/categories/{category}', [HookController::class, 'updateCategory'])->name('hooks.categories.update');
        Route::delete('hooks/categories/{category}', [HookController::class, 'destroyCategory'])->name('hooks.categories.destroy');
        Route::post('hooks/categories/{category}/reset', [HookController::class, 'resetCounters'])->name('hooks.categories.reset');

        // RSS Feeds
        Route::resource('rss-feeds', RssFeedController::class)->except(['show']);
        Route::post('rss-feeds/{rssFeed}/fetch', [RssFeedController::class, 'fetchNow'])->name('rss-feeds.fetch');
        Route::post('rss-feeds/{rssFeed}/generate', [RssFeedController::class, 'generateNow'])->name('rss-feeds.generate');
        Route::get('rss-feeds/{rssFeed}/preview', [RssFeedController::class, 'preview'])->name('rss-feeds.preview');
        Route::post('rss-feeds/{rssFeed}/generate-preview', [RssFeedController::class, 'generatePreview'])->name('rss-feeds.generatePreview');
        Route::post('rss-feeds/{rssFeed}/regenerate-item', [RssFeedController::class, 'regenerateItem'])->name('rss-feeds.regenerateItem');
        Route::post('rss-feeds/{rssFeed}/confirm-publications', [RssFeedController::class, 'confirmPublications'])->name('rss-feeds.confirmPublications');

        // WordPress Sites
        Route::resource('wordpress-sites', WordPressSiteController::class)->except(['show'])->parameters(['wordpress-sites' => 'wpSource']);
        Route::post('wordpress-sites/test-connection', [WordPressSiteController::class, 'testConnection'])->name('wordpress-sites.testConnection');
        Route::post('wordpress-sites/{wpSource}/fetch', [WordPressSiteController::class, 'fetchNow'])->name('wordpress-sites.fetch');
        Route::get('wordpress-sites/{wpSource}/preview', [WordPressSiteController::class, 'preview'])->name('wordpress-sites.preview');
        Route::post('wordpress-sites/{wpSource}/generate-preview', [WordPressSiteController::class, 'generatePreview'])->name('wordpress-sites.generatePreview');
        Route::post('wordpress-sites/{wpSource}/regenerate-item', [WordPressSiteController::class, 'regenerateItem'])->name('wordpress-sites.regenerateItem');
        Route::post('wordpress-sites/{wpSource}/confirm-publications', [WordPressSiteController::class, 'confirmPublications'])->name('wordpress-sites.confirmPublications');

        // YouTube Channels
        Route::resource('youtube-channels', YouTubeChannelController::class)->except(['show'])->parameters(['youtube-channels' => 'ytSource']);
        Route::post('youtube-channels/test-connection', [YouTubeChannelController::class, 'testConnection'])->name('youtube-channels.testConnection');
        Route::post('youtube-channels/{ytSource}/fetch', [YouTubeChannelController::class, 'fetchNow'])->name('youtube-channels.fetch');
        Route::get('youtube-channels/{ytSource}/preview', [YouTubeChannelController::class, 'preview'])->name('youtube-channels.preview');
        Route::post('youtube-channels/{ytSource}/generate-preview', [YouTubeChannelController::class, 'generatePreview'])->name('youtube-channels.generatePreview');
        Route::post('youtube-channels/{ytSource}/regenerate-item', [YouTubeChannelController::class, 'regenerateItem'])->name('youtube-channels.regenerateItem');
        Route::post('youtube-channels/{ytSource}/confirm-publications', [YouTubeChannelController::class, 'confirmPublications'])->name('youtube-channels.confirmPublications');

        // Reddit Sources
        Route::resource('reddit-sources', RedditSourceController::class)->except(['show'])->parameters(['reddit-sources' => 'redditSource']);
        Route::post('reddit-sources/test-connection', [RedditSourceController::class, 'testConnection'])->name('reddit-sources.testConnection');
        Route::post('reddit-sources/{redditSource}/fetch', [RedditSourceController::class, 'fetchNow'])->name('reddit-sources.fetch');
        Route::get('reddit-sources/{redditSource}/preview', [RedditSourceController::class, 'preview'])->name('reddit-sources.preview');
        Route::post('reddit-sources/{redditSource}/generate-preview', [RedditSourceController::class, 'generatePreview'])->name('reddit-sources.generatePreview');
        Route::post('reddit-sources/{redditSource}/regenerate-item', [RedditSourceController::class, 'regenerateItem'])->name('reddit-sources.regenerateItem');
        Route::post('reddit-sources/{redditSource}/confirm-publications', [RedditSourceController::class, 'confirmPublications'])->name('reddit-sources.confirmPublications');

        // Bot actions
        Route::get('bot', [BotController::class, 'index'])->name('bot.index');
        Route::post('bot/terms', [BotController::class, 'addTerm'])->name('bot.addTerm');
        Route::delete('bot/terms/{term}', [BotController::class, 'removeTerm'])->name('bot.removeTerm');
        Route::patch('bot/terms/{term}/toggle', [BotController::class, 'toggleTerm'])->name('bot.toggleTerm');
        Route::post('bot/run/bluesky', [BotController::class, 'runBluesky'])->name('bot.runBluesky');
        Route::post('bot/run/facebook', [BotController::class, 'runFacebook'])->name('bot.runFacebook');
        Route::get('bot/status', [BotController::class, 'botStatus'])->name('bot.status');
        Route::post('bot/status-batch', [BotController::class, 'botStatusBatch'])->name('bot.statusBatch');
        Route::post('bot/stop', [BotController::class, 'stopBot'])->name('bot.stop');
        Route::post('bot/frequency', [BotController::class, 'updateFrequency'])->name('bot.updateFrequency');
        Route::post('bot/option', [BotController::class, 'updateOption'])->name('bot.updateOption');
        Route::delete('bot/logs', [BotController::class, 'clearLogs'])->name('bot.clearLogs');
        Route::post('bot/targets', [BotController::class, 'addTarget'])->name('bot.addTarget');
        Route::delete('bot/targets/{target}', [BotController::class, 'removeTarget'])->name('bot.removeTarget');
        Route::post('bot/targets/{target}/run', [BotController::class, 'runTarget'])->name('bot.runTarget');
        Route::post('bot/targets/{target}/stop', [BotController::class, 'stopTarget'])->name('bot.stopTarget');
        Route::post('bot/targets/{target}/reset', [BotController::class, 'resetTarget'])->name('bot.resetTarget');
        Route::get('bot/target-status/{target}', [BotController::class, 'targetStatus'])->name('bot.targetStatus');
        Route::get('bot/api-status/{account}', [BotController::class, 'apiStatus'])->name('bot.apiStatus');

        // Cross-post (temporary tool)
        Route::get('tools/crosspost', [CrossPostController::class, 'index'])->name('crosspost.index');
        Route::post('tools/crosspost/fetch', [CrossPostController::class, 'fetchPosts'])->name('crosspost.fetch');
        Route::post('tools/crosspost/post', [CrossPostController::class, 'crossPost'])->name('crosspost.post');

        // Pinterest Feeds
        Route::get('tools/pinterest-feeds', [PinterestFeedController::class, 'index'])->name('pinterest-feeds.index');
        Route::post('tools/pinterest-feeds', [PinterestFeedController::class, 'store'])->name('pinterest-feeds.store');
        Route::put('tools/pinterest-feeds/{feed}', [PinterestFeedController::class, 'update'])->name('pinterest-feeds.update');
        Route::delete('tools/pinterest-feeds/{feed}', [PinterestFeedController::class, 'destroy'])->name('pinterest-feeds.destroy');
        Route::post('tools/pinterest-feeds/boards', [PinterestFeedController::class, 'boards'])->name('pinterest-feeds.boards');
        Route::post('tools/pinterest-feeds/{feed}/generate-pins', [PinterestFeedController::class, 'generatePins'])->name('pinterest-feeds.generatePins');
        Route::post('tools/pinterest-feeds/{feed}/batch-generate', [PinterestFeedController::class, 'batchGenerate'])->name('pinterest-feeds.batchGenerate');
        Route::get('tools/pinterest-feeds/{feed}/pins', [PinterestFeedController::class, 'pins'])->name('pinterest-feeds.pins');
        Route::post('tools/pinterest-feeds/pin/{pin}/generate', [PinterestFeedController::class, 'generatePinImage'])->name('pinterest-feeds.generatePinImage');
        Route::post('tools/pinterest-feeds/pin/{pin}/add-to-feed', [PinterestFeedController::class, 'addToFeed'])->name('pinterest-feeds.addToFeed');
        Route::post('tools/pinterest-feeds/pin/{pin}/repost', [PinterestFeedController::class, 'repost'])->name('pinterest-feeds.repost');

        // YouTube Translator
        Route::get('tools/yt-translator', [YouTubeTranslatorController::class, 'index'])->name('yt-translator.index');
        Route::post('tools/yt-translator/videos', [YouTubeTranslatorController::class, 'videos'])->name('yt-translator.videos');
        Route::post('tools/yt-translator/captions', [YouTubeTranslatorController::class, 'captions'])->name('yt-translator.captions');
        Route::post('tools/yt-translator/translate', [YouTubeTranslatorController::class, 'translate'])->name('yt-translator.translate');
        Route::post('tools/yt-translator/status', [YouTubeTranslatorController::class, 'status'])->name('yt-translator.status');
        Route::post('tools/yt-translator/language-groups', [YouTubeTranslatorController::class, 'storeLanguageGroup'])->name('yt-translator.languageGroups.store');
        Route::put('tools/yt-translator/language-groups/{languageGroup}', [YouTubeTranslatorController::class, 'updateLanguageGroup'])->name('yt-translator.languageGroups.update');
        Route::delete('tools/yt-translator/language-groups/{languageGroup}', [YouTubeTranslatorController::class, 'destroyLanguageGroup'])->name('yt-translator.languageGroups.destroy');

        // Settings
        Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::patch('settings', [SettingsController::class, 'update'])->name('settings.update');

        // Source items API (for thread creation source browser)
        Route::get('api/source-items/sources', [SourceItemController::class, 'sources'])->name('sourceItems.sources');
        Route::get('api/source-items/items', [SourceItemController::class, 'items'])->name('sourceItems.items');
    });

    // ─── Admin seulement ────────────────────────────────────────

    Route::middleware('role:admin')->group(function () {
        // Update system
        Route::get('update', [UpdateController::class, 'index'])->name('update.index');
        Route::post('update/check', [UpdateController::class, 'check'])->name('update.check');
        Route::post('update/deploy', [UpdateController::class, 'deploy'])->name('update.deploy');
        Route::get('update/status', [UpdateController::class, 'status'])->name('update.status');

        // User management
        Route::resource('users', UserController::class)->except(['show']);
        Route::patch('users/{user}/toggle-role', [UserController::class, 'toggleRole'])->name('users.toggleRole');

        // Social account creation & deletion (admin only)
        Route::get('accounts/create', [SocialAccountController::class, 'create'])->name('accounts.create');
        Route::post('accounts', [SocialAccountController::class, 'store'])->name('accounts.store');
        Route::delete('accounts/{account}', [SocialAccountController::class, 'destroy'])->name('accounts.destroy');

        // Platform account deletion (admin only)
        Route::delete('platforms/telegram/bot', [PlatformController::class, 'destroyTelegramBot'])->name('platforms.telegram.destroyBot');
        Route::delete('platforms/reddit/app', [PlatformController::class, 'destroyRedditApp'])->name('platforms.reddit.destroyApp');
        Route::delete('platforms/account/{account}', [PlatformController::class, 'destroyAccount'])->name('platforms.destroyAccount');
    });
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

// Pinterest RSS feeds (public, consumed by Pinterest)
Route::get('/feeds/pinterest/{slug}.xml', [PinterestFeedController::class, 'serveFeed'])->name('pinterest-feeds.serve');

// Health check (public, for monitoring)
Route::get('/health', HealthController::class)->name('health');

require __DIR__.'/auth.php';
