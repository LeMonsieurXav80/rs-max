<?php

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\CarouselApiController;
use App\Http\Controllers\Api\ExtensionApiController;
use App\Http\Controllers\Api\InboxApiController;
use App\Http\Controllers\Api\MediaApiController;
use App\Http\Controllers\Api\MetaAdsApiController;
use App\Http\Controllers\Api\MetaAudienceApiController;
use App\Http\Controllers\Api\PartnerApiController;
use App\Http\Controllers\Api\PostApiController;
use App\Http\Controllers\Api\ReshareApiController;
use App\Http\Controllers\Api\StatsApiController;
use App\Http\Controllers\Api\ThreadApiController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', \App\Http\Middleware\CommunityManagerTokenScope::class])->group(function () {
    Route::prefix('community-manager')->controller(\App\Http\Controllers\Api\CommunityManagerController::class)->group(function () {
        Route::get('/accounts/{id}/comment-context', 'commentContext');
        Route::get('accounts/{id}/strategy', [\App\Http\Controllers\Api\AccountStrategyController::class, 'show']);
        Route::patch('accounts/{id}/strategy', [\App\Http\Controllers\Api\AccountStrategyController::class, 'update']);
        Route::get('accounts/{id}/inbox', [\App\Http\Controllers\Api\CommunityManagerInboxController::class, 'index']);
        Route::get('inbox/{inboxItem}', [\App\Http\Controllers\Api\CommunityManagerInboxController::class, 'status']);
        Route::post('inbox/{inboxItem}/reconcile', [\App\Http\Controllers\Api\CommunityManagerInboxController::class, 'reconcile']);
        Route::post('inbox/{inboxItem}/review', [\App\Http\Controllers\Api\CommunityManagerInboxController::class, 'review']);
        Route::get('inbox/{inboxItem}/context', [\App\Http\Controllers\Api\CommunityManagerInboxController::class, 'context']);
        Route::post('inbox/{inboxItem}/reply', [\App\Http\Controllers\Api\CommunityManagerInboxController::class, 'reply']);
        Route::post('accounts/{id}/x/read', [\App\Http\Controllers\Api\XAgentController::class, 'read']);
        Route::get('accounts/{id}/x/context', [\App\Http\Controllers\Api\XAgentController::class, 'context']);
        Route::post('accounts/{id}/x/prepare', [\App\Http\Controllers\Api\XAgentController::class, 'prepare']);
        Route::get('accounts/{id}/x/contacts', [\App\Http\Controllers\Api\XAgentController::class, 'contacts']);
        Route::post('x/engagements/{engagement}/execute', [\App\Http\Controllers\Api\XAgentController::class, 'execute']);
        Route::get('x/engagements/{engagement}', [\App\Http\Controllers\Api\XAgentController::class, 'result']);
        Route::match(['get', 'post'], 'report', [\App\Http\Controllers\Api\CommunityManagerReportController::class, 'report']);
        Route::get('logs', [\App\Http\Controllers\Api\AutomationLogController::class, 'index']);
        Route::get('accounts', 'accounts');
        Route::post('read', 'read')->middleware('throttle:20,1');
        Route::get('snapshots/{id}', 'snapshot')->whereUuid('id');
        Route::post('prepare', 'prepare')->middleware('throttle:20,1');
        Route::post('engagements/{engagement}/execute', 'execute')->middleware('throttle:20,1');
        Route::get('engagements/{engagement}', 'result');
        Route::post('upload/inspect', 'uploadInspect')->middleware('throttle:5,1');
    });
    Route::post('agent/accounts/{id}/x/read', [\App\Http\Controllers\Api\XAgentController::class, 'read']);
    Route::get('agent/accounts/{id}/x/context', [\App\Http\Controllers\Api\XAgentController::class, 'context']);
    Route::post('agent/accounts/{id}/x/prepare', [\App\Http\Controllers\Api\XAgentController::class, 'prepare']);
    Route::get('agent/accounts/{id}/x/contacts', [\App\Http\Controllers\Api\XAgentController::class, 'contacts']);
    Route::post('agent/x/engagements/{engagement}/execute', [\App\Http\Controllers\Api\XAgentController::class, 'execute']);
    Route::get('agent/x/engagements/{engagement}', [\App\Http\Controllers\Api\XAgentController::class, 'result']);
    Route::get('agent/logs', [\App\Http\Controllers\Api\AutomationLogController::class, 'index']);
    // ── Auth ──
    Route::get('/me', [ApiController::class, 'me']);
    Route::get('/accounts', [ApiController::class, 'accounts']);
    Route::get('/accounts/{id}/strategy', [\App\Http\Controllers\Api\AccountStrategyController::class, 'show']);
    Route::patch('/accounts/{id}/strategy', [\App\Http\Controllers\Api\AccountStrategyController::class, 'update']);

    // ── Inbox : lots bornes, persona du compte, apercu avant envoi ──
    Route::get('/inbox', [InboxApiController::class, 'index']);
    Route::match(['get', 'post'], '/inbox/status', [InboxApiController::class, 'status']);
    Route::post('/inbox/ai-prepare', [InboxApiController::class, 'prepare']);
    Route::post('/inbox/{inboxItem}/ai-suggest', [InboxApiController::class, 'suggest'])->middleware('throttle:60,1');
    Route::post('/inbox/{inboxItem}/reply', [InboxApiController::class, 'reply'])->middleware('throttle:60,1');

    // ── Posts ──
    Route::get('/posts', [PostApiController::class, 'index']);
    Route::post('/posts', [PostApiController::class, 'store']);
    Route::get('/posts/{post}', [PostApiController::class, 'show']);
    Route::put('/posts/{post}', [PostApiController::class, 'update']);
    // Tag partenaire : accepté quel que soit le statut, y compris sur un post déjà publié.
    Route::put('/posts/{post}/partners', [PostApiController::class, 'updatePartners']);
    Route::delete('/posts/{post}', [PostApiController::class, 'destroy']);
    Route::post('/posts/{post}/publish', [PostApiController::class, 'publish']);
    Route::post('/posts/{post}/reshare', [ReshareApiController::class, 'fromPost']);
    Route::post('/posts/reshare-url', [ReshareApiController::class, 'fromUrl']);
    Route::post('/bulk-cancel', [PostApiController::class, 'bulkCancel']);

    // ── Threads ──
    Route::get('/threads', [ThreadApiController::class, 'index']);
    Route::post('/threads', [ThreadApiController::class, 'store']);
    Route::get('/threads/{thread}', [ThreadApiController::class, 'show']);
    Route::put('/threads/{thread}', [ThreadApiController::class, 'update']);
    Route::put('/threads/{thread}/partners', [ThreadApiController::class, 'updatePartners']);
    Route::delete('/threads/{thread}', [ThreadApiController::class, 'destroy']);
    Route::post('/threads/{thread}/publish', [ThreadApiController::class, 'publish']);

    // ── Partenaires / marques ──
    Route::get('/partners', [PartnerApiController::class, 'index']);
    Route::post('/partners', [PartnerApiController::class, 'store']);
    Route::get('/partners/{partner}/posts', [PartnerApiController::class, 'posts']); // avant /{partner}
    Route::get('/partners/{partner}/threads', [PartnerApiController::class, 'threads']);
    Route::get('/partners/{partner}/emv', [PartnerApiController::class, 'emv']);

    // ── Meta Ads ──
    // Lecture ouverte ; écriture en dry-run par défaut, derrière
    // META_ADS_WRITE_ENABLED et réservée aux managers. Pas de création ni de
    // suppression de campagne : hors périmètre à dessein.
    Route::get('/meta-ads/campaigns', [MetaAdsApiController::class, 'campaigns']);
    Route::get('/meta-ads/insights', [MetaAdsApiController::class, 'insights']); // avant /campaigns/{id}
    Route::get('/meta-ads/logs', [MetaAdsApiController::class, 'logs']);
    Route::get('/meta-ads/boosts', [MetaAdsApiController::class, 'boosts']);
    // Couples objectif / optimisation valides : a lire avant de composer un boost.
    Route::get('/meta-ads/objectives', [MetaAdsApiController::class, 'objectives']);
    // Ciblage : les identifiants d'interet, de lieu et de langue viennent de Meta,
    // ils ne s'inventent pas — un id fabrique donne une campagne qui ne touche personne.
    Route::get('/meta-ads/targeting/search', [MetaAdsApiController::class, 'searchTargeting']);
    Route::get('/meta-ads/targeting/custom-audiences', [MetaAdsApiController::class, 'customAudiences']);
    Route::post('/meta-ads/targeting/estimate', [MetaAdsApiController::class, 'estimate']);
    // Audiences enregistrees (objets RS-Max, pas des objets Meta).
    Route::get('/meta-ads/audiences', [MetaAudienceApiController::class, 'index']);
    Route::post('/meta-ads/audiences', [MetaAudienceApiController::class, 'store']);
    Route::get('/meta-ads/audiences/{audience}', [MetaAudienceApiController::class, 'show']);
    Route::put('/meta-ads/audiences/{audience}', [MetaAudienceApiController::class, 'update']);
    Route::patch('/meta-ads/audiences/{audience}', [MetaAudienceApiController::class, 'update']);
    Route::delete('/meta-ads/audiences/{audience}', [MetaAudienceApiController::class, 'destroy']);
    // Sponsorise une publication RS-Max deja publiee, sans la republier.
    // Cree la campagne EN PAUSE : l'activation passe par /status.
    Route::post('/meta-ads/boost', [MetaAdsApiController::class, 'boost']);
    Route::get('/meta-ads/campaigns/{object}', [MetaAdsApiController::class, 'campaign']);
    Route::post('/meta-ads/{object}/status', [MetaAdsApiController::class, 'updateStatus']);
    Route::post('/meta-ads/{object}/budget', [MetaAdsApiController::class, 'updateBudget']);
    // Ciblage et dates d'un ad set. Le budget garde son endpoint : c'est lui
    // qui passe par les plafonds.
    Route::post('/meta-ads/{object}/adset', [MetaAdsApiController::class, 'updateAdSet']);
    // (Dé)taguage en masse de photos. Ne touche que des pivots, jamais la fiche.
    // dry_run à true par défaut. {partner} accepte l'id ou le slug ici.
    Route::post('/partners/{partner}/media/detach', [PartnerApiController::class, 'detachMedia']);
    Route::post('/partners/{partner}/media/attach', [PartnerApiController::class, 'attachMedia']);
    Route::get('/partners/{partner}', [PartnerApiController::class, 'show']);
    Route::put('/partners/{partner}', [PartnerApiController::class, 'update']);
    Route::patch('/partners/{partner}', [PartnerApiController::class, 'update']);
    Route::delete('/partners/{partner}', [PartnerApiController::class, 'destroy']);

    // ── Stats ──
    Route::get('/stats/overview', [StatsApiController::class, 'overview']);
    Route::get('/stats/audience', [StatsApiController::class, 'audience']);
    Route::get('/stats/top-posts', [StatsApiController::class, 'topPosts']);
    Route::get('/stats/platforms', [StatsApiController::class, 'platforms']);
    Route::get('/calendar', [StatsApiController::class, 'calendar']);

    // ── Catalogue média (pipeline Mac + recherche sémantique) ──
    Route::post('/media/ingest', [MediaApiController::class, 'ingest']);
    // Dépôt sans retouche : l'octet exact, image ou vidéo. La normalisation
    // qu'un réseau impose arrive à la publication, dans une variante à côté.
    // `/ingest` reste le chemin du pipeline Mac (phash + embedding requis).
    Route::post('/media/upload', [MediaApiController::class, 'upload']);
    Route::get('/media/search', [MediaApiController::class, 'search']);
    Route::get('/media/folders', [MediaApiController::class, 'folders']);
    // Écriture sur les dossiers : avant `/media/{media}` pour ne pas être capturé.
    Route::post('/media/folders', [MediaApiController::class, 'createFolder']);
    Route::patch('/media/folders/{folder}', [MediaApiController::class, 'updateFolder']);
    Route::put('/media/folders/{folder}', [MediaApiController::class, 'updateFolder']);
    Route::get('/media/pending-analysis', [MediaApiController::class, 'pendingAnalysis']);
    Route::get('/media/{media}', [MediaApiController::class, 'show']); // après /search, /folders et /pending-analysis pour ne pas les capturer
    Route::patch('/media/{media}', [MediaApiController::class, 'updateDescription']); // édition description/tags only (refuse dossiers privés)
    Route::post('/media/{media}/validate', [MediaApiController::class, 'validateMedia']);
    Route::post('/media/{media}/enrich', [MediaApiController::class, 'enrich']);
    Route::post('/media/{media}/mark-published', [MediaApiController::class, 'markPublished']);
    Route::post('/media/{media}/mark-wp-used', [MediaApiController::class, 'markWpUsed']); // trace l'usage sur un site WordPress (idempotent par-site)
    Route::post('/media/{media}/analyze-vision', [MediaApiController::class, 'analyzeVision']);

    // ── Studio carrousel (briques HTML/CSS) ──
    Route::get('/carousel/bricks', [CarouselApiController::class, 'bricks']);
    Route::get('/carousel/fonts', [CarouselApiController::class, 'fonts']); // valeurs acceptables pour theme.*_font
    // CRUD des briques STOCKÉES EN BASE (les briques fournies restent en lecture seule)
    Route::post('/carousel/bricks', [CarouselApiController::class, 'storeBrick']);
    Route::put('/carousel/bricks/{brick}', [CarouselApiController::class, 'updateBrick']);
    Route::delete('/carousel/bricks/{brick}', [CarouselApiController::class, 'destroyBrick']);
    Route::post('/carousel/preview', [CarouselApiController::class, 'preview']); // HTML de la bande, sans Chromium
    Route::post('/carousel/render', [CarouselApiController::class, 'render'])
        ->middleware('throttle:20,1'); // rendu synchrone Chromium : ~2 s par slide
    // Une brique employée seule => une image (visuel de tweet, vignette d'article).
    Route::post('/carousel/image', [CarouselApiController::class, 'image'])
        ->middleware('throttle:60,1'); // une seule rasterisation : plafond plus large que /render
    // Lien vers le Studio pré-rempli : l'IA dégrossit, l'humain peaufine.
    Route::post('/carousel/studio-link', [CarouselApiController::class, 'studioLink']);

    // ── Banques d'images externes (Pexels, Pixabay, Unsplash) ──
    Route::get('/stock-photos/search', [MediaApiController::class, 'stockPhotosSearch']);

    // ── Agent : réponses compactes, voir docs/API.md ──
    Route::match(['get', 'post'], '/agent/report', [\App\Http\Controllers\Api\CommunityManagerReportController::class, 'report']);
    Route::get('/agent/accounts', [\App\Http\Controllers\Api\AgentApiController::class, 'accounts']);
    Route::post('/agent/accounts/{id}/tiktok/read', [\App\Http\Controllers\Api\TikTokFeedController::class, 'read'])->whereNumber('id')->middleware('throttle:20,1');
    Route::post('/agent/accounts/{id}/tiktok/prepare', [\App\Http\Controllers\Api\TikTokFeedController::class, 'prepare'])->whereNumber('id')->middleware('throttle:20,1');
    Route::get('/tiktok/engagements/{engagement}', [\App\Http\Controllers\Api\TikTokFeedController::class, 'show']);
    Route::post('/tiktok/engagements/{engagement}/send', [\App\Http\Controllers\Api\TikTokFeedController::class, 'send'])->middleware('throttle:20,1');
    Route::get('/agent/log', [\App\Http\Controllers\Api\AgentApiController::class, 'log']);
    Route::get('/agent/drafts', [\App\Http\Controllers\Api\AgentApiController::class, 'drafts']);
    Route::post('/agent/accounts/{id}/drafts', [\App\Http\Controllers\Api\AgentApiController::class, 'prepare'])->whereNumber('id')->middleware('throttle:20,1');
    Route::get('/agent/accounts/{id}/read/{action}', [\App\Http\Controllers\Api\AgentApiController::class, 'read'])->whereNumber('id');
    Route::put('/agent/accounts/{id}/actions/{action}', [\App\Http\Controllers\Api\AgentApiController::class, 'configure'])->whereNumber('id');

    // ── Extension Chrome (RS-Max Companion) ──
    // Remontée en lot : l'extension bufferise puis pousse, d'où le plafond large.
    Route::post('/extension/actions', [ExtensionApiController::class, 'actions'])
        ->middleware('throttle:120,1');
    Route::get('/extension/summary', [ExtensionApiController::class, 'summary']);
    Route::get('/extension/x/context', [\App\Http\Controllers\Api\XExtensionController::class, 'context']);
    Route::put('/extension/x/context', [\App\Http\Controllers\Api\XExtensionController::class, 'bind']);
    Route::get('/extension/tasks/next', [\App\Http\Controllers\Api\BrowserTaskController::class, 'next']);
    Route::get('/extension/tasks/{task}/session', [\App\Http\Controllers\Api\BrowserTaskController::class, 'session']);
    Route::post('/extension/tasks/{task}/finish', [\App\Http\Controllers\Api\BrowserTaskController::class, 'finish']);
    Route::post('/extension/tasks/{task}/progress', [\App\Http\Controllers\Api\BrowserTaskController::class, 'progress'])->middleware('throttle:240,1');
    Route::get('/extension/x/session', [\App\Http\Controllers\Api\XExtensionController::class, 'sessionStatus']);
    Route::post('/extension/x/session', [\App\Http\Controllers\Api\XExtensionController::class, 'session'])->middleware('throttle:10,1');
    Route::post('/extension/x/suggest', [\App\Http\Controllers\Api\XExtensionController::class, 'suggest'])->middleware('throttle:30,1');
    Route::post('/extension/x/{id}/begin', [\App\Http\Controllers\Api\XExtensionController::class, 'begin']);
    Route::post('/extension/x/{id}/finish', [\App\Http\Controllers\Api\XExtensionController::class, 'finish']);
    Route::get('/extension/tiktok/comments', [\App\Http\Controllers\Api\TikTokExtensionController::class, 'comments']);
    Route::get('/extension/tiktok/context', [\App\Http\Controllers\Api\TikTokExtensionController::class, 'context']);
    Route::put('/extension/tiktok/context', [\App\Http\Controllers\Api\TikTokExtensionController::class, 'bind']);
    Route::post('/extension/tiktok/session', [\App\Http\Controllers\Api\TikTokExtensionController::class, 'session']);
    Route::post('/extension/tiktok/suggest', [\App\Http\Controllers\Api\TikTokExtensionController::class, 'suggest'])->middleware('throttle:30,1');
    Route::post('/extension/tiktok/{id}/begin', [\App\Http\Controllers\Api\TikTokExtensionController::class, 'begin']);
    Route::post('/extension/tiktok/{id}/finish', [\App\Http\Controllers\Api\TikTokExtensionController::class, 'finish']);
});
