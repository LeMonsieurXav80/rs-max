<?php

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\CarouselApiController;
use App\Http\Controllers\Api\GenerateApiController;
use App\Http\Controllers\Api\MediaApiController;
use App\Http\Controllers\Api\PartnerApiController;
use App\Http\Controllers\Api\PersonaApiController;
use App\Http\Controllers\Api\PostApiController;
use App\Http\Controllers\Api\ReshareApiController;
use App\Http\Controllers\Api\StatsApiController;
use App\Http\Controllers\Api\ThreadApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // ── Auth ──
    Route::get('/me', [ApiController::class, 'me']);
    Route::get('/accounts', [ApiController::class, 'accounts']);

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
    Route::post('/bulk-schedule', [ApiController::class, 'bulkSchedule']);
    Route::post('/bulk-cancel', [PostApiController::class, 'bulkCancel']);

    // ── Threads ──
    Route::get('/threads', [ThreadApiController::class, 'index']);
    Route::post('/threads', [ThreadApiController::class, 'store']);
    Route::get('/threads/{thread}', [ThreadApiController::class, 'show']);
    Route::put('/threads/{thread}', [ThreadApiController::class, 'update']);
    Route::put('/threads/{thread}/partners', [ThreadApiController::class, 'updatePartners']);
    Route::delete('/threads/{thread}', [ThreadApiController::class, 'destroy']);
    Route::post('/threads/{thread}/publish', [ThreadApiController::class, 'publish']);
    Route::post('/bulk-schedule-threads', [ApiController::class, 'bulkScheduleThreads']);

    // ── Personas ──
    Route::get('/personas', [PersonaApiController::class, 'index']);
    Route::post('/personas', [PersonaApiController::class, 'store']);
    Route::get('/personas/{persona}', [PersonaApiController::class, 'show']);
    Route::put('/personas/{persona}', [PersonaApiController::class, 'update']);
    Route::delete('/personas/{persona}', [PersonaApiController::class, 'destroy']);

    // ── Partenaires / marques ──
    Route::get('/partners', [PartnerApiController::class, 'index']);
    Route::post('/partners', [PartnerApiController::class, 'store']);
    Route::get('/partners/{partner}/posts', [PartnerApiController::class, 'posts']); // avant /{partner}
    Route::get('/partners/{partner}/threads', [PartnerApiController::class, 'threads']);
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

    // ── Génération IA (preview) ──
    Route::post('/generate', [GenerateApiController::class, 'generate']);
    Route::post('/generate-thread', [GenerateApiController::class, 'generateThread']);

    // ── Catalogue média (pipeline Mac + recherche sémantique) ──
    Route::post('/media/ingest', [MediaApiController::class, 'ingest']);
    Route::get('/media/search', [MediaApiController::class, 'search']);
    Route::get('/media/folders', [MediaApiController::class, 'folders']);
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
});
